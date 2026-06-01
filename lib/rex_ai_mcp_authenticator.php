<?php

declare(strict_types=1);

/**
 * Resolves the auth context for an incoming MCP request.
 *
 * Phase 1: stub that always returns an anonymous context. Phase 2 will
 * validate OAuth 2.1 Bearer tokens against rex_ai_oauth_token and resolve
 * the linked YCom user + scopes.
 */
final class rex_ai_mcp_authenticator
{
    public function authenticate(): rex_ai_mcp_context
    {
        $token = self::extractBearerToken();
        if (null === $token) {
            return rex_ai_mcp_context::anonymous();
        }

        // Phase 2: look up token in rex_ai_oauth_token, validate, return
        // an authenticated context with the bound user and scopes.
        // Until then, an unknown bearer simply downgrades to anonymous so
        // pre-flight discovery still works.
        return rex_ai_mcp_context::anonymous();
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
     */
    public static function buildChallengeHeader(): string
    {
        $resource = rex::getServer() . '.well-known/oauth-protected-resource';
        return sprintf('Bearer realm="MCP", resource="%s"', $resource);
    }
}
