<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\OAuth;

use rex_response;

/**
 * Handler for `POST /oauth/token`.
 *
 * Supports two grant types per OAuth 2.1 (RFC 6749 + RFC 7636 PKCE):
 *
 *   - authorization_code:
 *       code, redirect_uri, client_id, code_verifier are required.
 *       For confidential clients client_secret is also required.
 *   - refresh_token:
 *       refresh_token, client_id are required.
 *       For confidential clients client_secret is also required.
 *
 * Always returns the OAuth-standard JSON envelope and the correct HTTP
 * status — 200 on success, 400 for grant errors, 401 for client-auth
 * failures.
 */
final class TokenEndpoint
{
    public static function dispatch(): never
    {
        rex_response::cleanOutputBuffers();
        // Tokens must never be cached upstream
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Content-Type: application/json');

        $params = self::readParams();
        $grantType = (string) ($params['grant_type'] ?? '');

        try {
            $payload = match ($grantType) {
                'authorization_code' => self::handleAuthorizationCode($params),
                'refresh_token' => self::handleRefreshToken($params),
                '' => throw new OAuthError('invalid_request', 'grant_type is required'),
                default => throw new OAuthError('unsupported_grant_type', 'Unsupported grant_type: ' . $grantType),
            };
        } catch (OAuthError $e) {
            http_response_code($e->httpStatus);
            echo json_encode([
                'error' => $e->oauthError,
                'error_description' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            exit;
        }

        http_response_code(200);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Accepts both application/x-www-form-urlencoded and application/json.
     *
     * @return array<string, mixed>
     */
    private static function readParams(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        // PHP populates $_POST for form-encoded bodies automatically
        return $_POST;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function handleAuthorizationCode(array $params): array
    {
        foreach (['code', 'redirect_uri', 'client_id', 'code_verifier'] as $required) {
            if (empty($params[$required])) {
                throw new OAuthError('invalid_request', 'Missing required parameter: ' . $required);
            }
        }

        $clientId = (string) $params['client_id'];
        $client = self::authenticateClient($clientId, $params);

        $code = (string) $params['code'];
        $row = TokenStore::consumeAuthorizationCode($code);
        if (null === $row) {
            throw new OAuthError('invalid_grant', 'Authorization code is invalid, used or expired');
        }

        if ($row['client_id'] !== $clientId) {
            throw new OAuthError('invalid_grant', 'Authorization code was issued to a different client');
        }

        if ($row['redirect_uri'] !== (string) $params['redirect_uri']) {
            throw new OAuthError('invalid_grant', 'redirect_uri does not match the value used at /oauth/authorize');
        }

        if (!self::verifyPkce((string) $params['code_verifier'], (string) $row['code_challenge'], (string) $row['code_challenge_method'])) {
            throw new OAuthError('invalid_grant', 'PKCE code_verifier does not match code_challenge');
        }

        $tokens = TokenStore::issueTokenPair(
            $clientId,
            (int) $row['ycom_user_id'],
            $row['scopes'],
        );

        ClientStore::markUsed($clientId);
        // Note: $client is fetched but only used to drive authenticateClient's
        // validation. The actual scope set comes from the consumed code row.
        unset($client);

        return [
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            'refresh_token' => $tokens['refresh_token'],
            'scope' => implode(' ', $row['scopes']),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function handleRefreshToken(array $params): array
    {
        foreach (['refresh_token', 'client_id'] as $required) {
            if (empty($params[$required])) {
                throw new OAuthError('invalid_request', 'Missing required parameter: ' . $required);
            }
        }

        $clientId = (string) $params['client_id'];
        self::authenticateClient($clientId, $params);

        $tokens = TokenStore::rotateRefreshToken((string) $params['refresh_token'], $clientId);
        if (null === $tokens) {
            throw new OAuthError('invalid_grant', 'refresh_token is invalid, revoked or expired');
        }

        // The rotated pair carries the original scopes — we don't currently
        // narrow them further. RFC 6749 §6 allows the client to request a
        // narrower scope; left as future work since it's optional.
        $access = TokenStore::findAccessToken($tokens['access_token']);

        ClientStore::markUsed($clientId);

        return [
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            'refresh_token' => $tokens['refresh_token'],
            'scope' => null !== $access ? implode(' ', $access['scopes']) : '',
        ];
    }

    /**
     * Resolves the client and, for confidential clients, verifies the
     * client_secret. Throws on any client-side auth failure.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function authenticateClient(string $clientId, array $params): array
    {
        $client = ClientStore::findByClientId($clientId);
        if (null === $client) {
            throw new OAuthError('invalid_client', 'Unknown client_id', 401);
        }
        if (ClientStore::isExpired($client)) {
            // Expired registration → force the client to register again (DCR).
            throw new OAuthError('invalid_client', 'Client registration has expired, please register again', 401);
        }

        if (ClientStore::TYPE_CONFIDENTIAL === $client['type']) {
            $secret = (string) ($params['client_secret'] ?? '');
            if ('' === $secret) {
                throw new OAuthError('invalid_client', 'client_secret is required for confidential clients', 401);
            }
            if (!ClientStore::verifySecret($clientId, $secret)) {
                throw new OAuthError('invalid_client', 'client_secret does not match', 401);
            }
        }

        return $client;
    }

    private static function verifyPkce(string $verifier, string $challenge, string $method): bool
    {
        if ('S256' !== $method) {
            // We declared S256-only in the discovery metadata. Reject anything else.
            return false;
        }
        if (strlen($verifier) < 43 || strlen($verifier) > 128) {
            return false;
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($expected, $challenge);
    }
}
