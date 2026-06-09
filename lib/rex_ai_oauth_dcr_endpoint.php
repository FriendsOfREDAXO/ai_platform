<?php

declare(strict_types=1);

/**
 * Handler for `POST /oauth/register` — Dynamic Client Registration (RFC 7591).
 *
 * Always auto-approves: the OAuth 2.1 spec recommends DCR be open for
 * MCP-style workflows where clients self-register before their first
 * authorization request. This keeps the out-of-the-box flow with
 * mcp-remote / Claude Desktop / Cursor working without manual admin
 * intervention. Manual / confidential clients are still created via the
 * backend page.
 *
 * Accepts:
 *   - application/json body
 *   - Required: redirect_uris (array of strings, at least one)
 *   - Optional: client_name (defaults to "Dynamic client")
 *   - Optional: token_endpoint_auth_method — "none" (public, PKCE only) or
 *     "client_secret_post" (confidential; a client_secret is generated and
 *     returned once). Defaults to "none". Anything else is rejected.
 *
 * Returns the client_id (plus client_secret for confidential clients) along
 * with the supplied metadata per RFC 7591 §3.2.
 */
final class rex_ai_oauth_dcr_endpoint
{
    public static function dispatch(): never
    {
        rex_response::cleanOutputBuffers();
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');

        $body = (string) file_get_contents('php://input');
        $params = json_decode($body, true);
        if (!is_array($params)) {
            self::error(400, 'invalid_client_metadata', 'Request body must be valid JSON');
        }

        $redirectUris = $params['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || [] === $redirectUris) {
            self::error(400, 'invalid_redirect_uri', 'At least one redirect_uri is required');
        }
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || '' === trim($uri)) {
                self::error(400, 'invalid_redirect_uri', 'redirect_uris entries must be non-empty strings');
            }
            $parts = parse_url($uri);
            if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
                self::error(400, 'invalid_redirect_uri', 'Each redirect_uri must be an absolute URL: ' . $uri);
            }
        }

        // Match the methods advertised in the authorization-server metadata.
        //   - "none"               → public client (PKCE only)
        //   - "client_secret_post" → confidential client; secret sent in the
        //                            token request body (verified by the token
        //                            endpoint). Used by Claude / claude.ai.
        $authMethod = strtolower((string) ($params['token_endpoint_auth_method'] ?? 'none'));
        if (!in_array($authMethod, ['none', 'client_secret_post'], true)) {
            self::error(400, 'invalid_client_metadata', 'token_endpoint_auth_method must be "none" or "client_secret_post"');
        }
        $type = 'none' === $authMethod
            ? rex_ai_oauth_client_store::TYPE_PUBLIC
            : rex_ai_oauth_client_store::TYPE_CONFIDENTIAL;

        $clientName = trim((string) ($params['client_name'] ?? ''));
        if ('' === $clientName) {
            $clientName = 'Dynamic client';
        }
        if (strlen($clientName) > 250) {
            $clientName = substr($clientName, 0, 250);
        }

        $created = rex_ai_oauth_client_store::create(
            $clientName,
            array_values($redirectUris),
            $type,
            true,
        );

        $response = [
            'client_id' => $created['client_id'],
            'client_id_issued_at' => time(),
            'client_name' => $clientName,
            'redirect_uris' => array_values($redirectUris),
            'token_endpoint_auth_method' => $authMethod,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ];
        // For confidential clients the secret is returned exactly once (RFC 7591
        // §3.2.1). client_secret_expires_at = 0 means it does not expire.
        if (null !== $created['client_secret']) {
            $response['client_secret'] = $created['client_secret'];
            $response['client_secret_expires_at'] = 0;
        }

        http_response_code(201);
        echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function error(int $status, string $code, string $description): never
    {
        http_response_code($status);
        echo json_encode([
            'error' => $code,
            'error_description' => $description,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
