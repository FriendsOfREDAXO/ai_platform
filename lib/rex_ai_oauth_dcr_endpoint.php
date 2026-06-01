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
 *   - Optional: token_endpoint_auth_method (must be "none" — we only
 *     issue public clients via DCR)
 *
 * Returns the client_id along with the supplied metadata per RFC 7591 §3.2.
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

        $authMethod = strtolower((string) ($params['token_endpoint_auth_method'] ?? 'none'));
        if ('none' !== $authMethod) {
            self::error(400, 'invalid_client_metadata', 'token_endpoint_auth_method must be "none" — DCR only issues public clients');
        }

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
            rex_ai_oauth_client_store::TYPE_PUBLIC,
            true,
        );

        http_response_code(201);
        echo json_encode([
            'client_id' => $created['client_id'],
            'client_id_issued_at' => time(),
            'client_name' => $clientName,
            'redirect_uris' => array_values($redirectUris),
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
