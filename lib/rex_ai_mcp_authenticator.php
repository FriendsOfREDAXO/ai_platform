<?php

declare(strict_types=1);

/**
 * Resolves the auth context for an incoming MCP request.
 *
 * Two outcomes per request:
 *   1. No Authorization header        → anonymous context (only public
 *                                       tools callable)
 *   2. Valid OAuth Bearer token       → authenticated context with the
 *                                       linked YCom user + scopes
 *   3. Invalid / expired / revoked    → throws rex_ai_mcp_invalid_token_exception
 *      Bearer                          which the server converts into a
 *                                       401 + WWW-Authenticate response
 *                                       per RFC 6750 §3.1
 */
final class rex_ai_mcp_authenticator
{
    public function authenticate(): rex_ai_mcp_context
    {
        $token = self::extractBearerToken();
        if (null === $token) {
            return rex_ai_mcp_context::anonymous();
        }

        $row = rex_ai_oauth_token_store::findAccessToken($token);
        if (null === $row) {
            throw new rex_ai_mcp_invalid_token_exception('Access token is invalid, revoked or expired');
        }

        return new rex_ai_mcp_context(
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
        $resource = rex_ai_mcp_router::baseUrl() . '/.well-known/oauth-protected-resource';
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

/**
 * Thrown by the authenticator when the client sent a Bearer token that
 * does not resolve to a valid access token. The server catches this and
 * emits a 401 + WWW-Authenticate response per RFC 6750.
 */
final class rex_ai_mcp_invalid_token_exception extends \RuntimeException
{
}
