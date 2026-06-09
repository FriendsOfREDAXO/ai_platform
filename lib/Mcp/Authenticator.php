<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use FriendsOfRedaxo\AiPlatform\OAuth\TokenStore;

/**
 * Resolves the auth context for an incoming MCP request.
 *
 * Two outcomes per request:
 *   1. No Authorization header        → anonymous context (only public
 *                                       tools callable)
 *   2. Valid OAuth Bearer token       → authenticated context with the
 *                                       linked YCom user + scopes
 *   3. Invalid / expired / revoked    → throws InvalidTokenException
 *      Bearer                          which the server converts into a
 *                                       401 + WWW-Authenticate response
 *                                       per RFC 6750 §3.1
 */
final class Authenticator
{
    public function authenticate(): Context
    {
        $token = self::extractBearerToken();
        if (null === $token) {
            return Context::anonymous();
        }

        $row = TokenStore::findAccessToken($token);
        if (null === $row) {
            throw new InvalidTokenException('Access token is invalid, revoked or expired');
        }

        return new Context(
            ycomUserId: (int) $row['ycom_user_id'],
            scopes: $row['scopes'],
            authMode: 'oauth',
            clientId: (string) $row['client_id'],
        );
    }

    /**
     * Extracts the Bearer token from the Authorization header.
     */
    public static function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ('' === $header && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if ('' === $header) {
            return null;
        }
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));
        return '' === $token ? null : $token;
    }

    /**
     * Builds the WWW-Authenticate header value that points MCP clients at
     * the OAuth protected-resource discovery endpoint.
     *
     * @param string|null $error RFC 6750 §3.1 error code, e.g. "invalid_token"
     */
    public static function buildChallengeHeader(?string $error = null, ?string $errorDescription = null): string
    {
        $resource = Router::baseUrl() . '/.well-known/oauth-protected-resource';
        $parts = [
            'realm="MCP"',
            'resource="' . $resource . '"',
        ];
        if (null !== $error) {
            $parts[] = 'error="' . $error . '"';
        }
        if (null !== $errorDescription) {
            $parts[] = 'error_description="' . str_replace('"', "'", $errorDescription) . '"';
        }
        return 'Bearer ' . implode(', ', $parts);
    }
}
