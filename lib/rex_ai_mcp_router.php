<?php

declare(strict_types=1);

/**
 * Matches incoming frontend requests against the MCP / OAuth path table and
 * dispatches them. Bound to the PACKAGES_INCLUDED extension point so it
 * fires after all addons booted but before the structure / yrewrite routing
 * takes over.
 *
 * Routes (Phase 1):
 *
 *   POST /mcp                                    → MCP JSON-RPC
 *   GET  /.well-known/oauth-protected-resource   → discovery JSON
 *   GET  /.well-known/oauth-authorization-server → discovery JSON
 *   GET  /oauth/{authorize,token,register}       → 501 Not Implemented (Phase 2)
 *
 * Anything else passes through untouched.
 */
final class rex_ai_mcp_router
{
    private const MCP_PATH = '/mcp';
    private const DISCOVERY_PROTECTED_RESOURCE = '/.well-known/oauth-protected-resource';
    private const DISCOVERY_AUTH_SERVER = '/.well-known/oauth-authorization-server';
    private const OAUTH_TOKEN = '/oauth/token';
    private const OAUTH_PREFIX = '/oauth/';

    public static function dispatch(): void
    {
        if (rex::isBackend()) {
            return;
        }

        $path = self::currentPath();
        if (null === $path) {
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if (self::MCP_PATH === $path) {
            self::dispatchMcp($method);
        }

        if (self::DISCOVERY_PROTECTED_RESOURCE === $path) {
            self::dispatchProtectedResourceMetadata();
        }

        if (self::DISCOVERY_AUTH_SERVER === $path) {
            self::dispatchAuthorizationServerMetadata();
        }

        if (self::OAUTH_TOKEN === $path) {
            self::dispatchOauthToken($method);
        }

        if (str_starts_with($path, self::OAUTH_PREFIX)) {
            self::dispatchOauthNotYetImplemented($path);
        }
    }

    private static function dispatchOauthToken(string $method): never
    {
        if ('POST' !== $method) {
            rex_response::cleanOutputBuffers();
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'invalid_request',
                'error_description' => 'POST required',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            exit;
        }
        rex_ai_oauth_token_endpoint::dispatch();
    }

    private static function currentPath(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ('' === $uri) {
            return null;
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            return null;
        }

        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private static function dispatchMcp(string $method): never
    {
        if ('POST' !== $method) {
            rex_response::cleanOutputBuffers();
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: text/plain');
            echo 'Method Not Allowed';
            exit;
        }

        $server = new rex_ai_mcp_server(new rex_ai_mcp_authenticator());
        $server->handle();
    }

    private static function dispatchProtectedResourceMetadata(): never
    {
        rex_response::cleanOutputBuffers();
        header('Content-Type: application/json');
        http_response_code(200);

        $base = self::baseUrl();
        echo json_encode([
            'resource' => $base . self::MCP_PATH,
            'authorization_servers' => [$base . '/'],
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => $base . '/redaxo/index.php?page=ai_platform/docs',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function dispatchAuthorizationServerMetadata(): never
    {
        rex_response::cleanOutputBuffers();
        header('Content-Type: application/json');
        http_response_code(200);

        $base = self::baseUrl();
        echo json_encode([
            'issuer' => $base . '/',
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Builds the base URL that backs OAuth discovery / redirect URIs.
     *
     * rex::getServer() reflects the static REDAXO config and can be wrong
     * (e.g. http://… while the site is actually reachable over HTTPS).
     * That breaks OAuth flows because clients refuse mixed-scheme redirects.
     * We derive the scheme from the actual request, falling back to the
     * configured value when no request signal is available.
     */
    public static function baseUrl(): string
    {
        $configured = rtrim(rex::getServer(), '/');
        $scheme = self::detectScheme();
        if (null === $scheme) {
            return $configured;
        }

        $parts = parse_url($configured);
        if (false === $parts || !isset($parts['host'])) {
            return $configured;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $parts['host'] . $port;
    }

    private static function detectScheme(): ?string
    {
        if (!empty($_SERVER['HTTPS']) && 'off' !== strtolower((string) $_SERVER['HTTPS'])) {
            return 'https';
        }
        $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        if ('' !== $forwarded) {
            $first = trim(explode(',', $forwarded)[0]);
            if ('https' === strtolower($first) || 'http' === strtolower($first)) {
                return strtolower($first);
            }
        }
        if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return 'https';
        }
        return null;
    }

    private static function dispatchOauthNotYetImplemented(string $path): never
    {
        rex_response::cleanOutputBuffers();
        http_response_code(501);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'not_implemented',
            'error_description' => 'OAuth endpoint ' . $path . ' is planned for phase 2.',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
