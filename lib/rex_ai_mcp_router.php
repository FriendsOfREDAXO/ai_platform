<?php

declare(strict_types=1);

/**
 * Matches incoming frontend requests against the MCP / OAuth path table and
 * dispatches them. Bound to the PACKAGES_INCLUDED extension point so it
 * fires after all addons booted but before the structure / yrewrite routing
 * takes over.
 *
 * Routes:
 *
 *   POST     /mcp                                    → MCP JSON-RPC
 *   GET      /.well-known/oauth-protected-resource   → discovery JSON
 *   GET      /.well-known/oauth-authorization-server → discovery JSON
 *   GET/POST /oauth/authorize                        → OAuth login + consent
 *   POST     /oauth/token                            → token endpoint (code + refresh)
 *   POST     /oauth/register                         → dynamic client registration
 *   *        /oauth/...                               → 404 Not Found (unknown endpoint)
 *
 * Anything else passes through untouched.
 */
final class rex_ai_mcp_router
{
    private const MCP_PATH = '/mcp';
    private const DISCOVERY_PROTECTED_RESOURCE = '/.well-known/oauth-protected-resource';
    private const DISCOVERY_AUTH_SERVER = '/.well-known/oauth-authorization-server';
    private const OAUTH_AUTHORIZE = '/oauth/authorize';
    private const OAUTH_TOKEN = '/oauth/token';
    private const OAUTH_REGISTER = '/oauth/register';
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

        if (self::OAUTH_AUTHORIZE === $path) {
            self::dispatchOauthAuthorize();
        }

        if (self::OAUTH_TOKEN === $path) {
            self::dispatchOauthToken($method);
        }

        if (self::OAUTH_REGISTER === $path) {
            self::dispatchOauthRegister($method);
        }

        if (str_starts_with($path, self::OAUTH_PREFIX)) {
            self::dispatchOauthUnknownEndpoint($path);
        }
    }

    private static function dispatchOauthAuthorize(): never
    {
        rex_ai_oauth_authorization_endpoint::dispatch();
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

    private static function dispatchOauthRegister(string $method): never
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
        rex_ai_oauth_dcr_endpoint::dispatch();
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
     * Builds the base URL that backs OAuth discovery / redirect challenges.
     *
     * Derives scheme + host from the actual request, honouring a forwarding
     * proxy (X-Forwarded-Proto / X-Forwarded-Host). This is required whenever
     * the site is reached under a different host than rex::getServer() — behind
     * a reverse proxy, load balancer or a tunnel (ngrok/Cloudflare): discovery,
     * issuer and the redirect challenge must advertise the host the client
     * actually connected to, otherwise the client tries to reach the internal
     * host and the OAuth flow (DCR, authorize, token) breaks. Falls back to
     * rex::getServer() when no request signal is available (e.g. CLI).
     */
    public static function baseUrl(): string
    {
        $configured = rtrim(rex::getServer(), '/');
        $scheme = self::detectScheme();
        $host = self::detectHost();

        if (null === $scheme && null === $host) {
            return $configured;
        }

        $parts = parse_url($configured);
        $configuredHost = (is_array($parts) && isset($parts['host'])) ? $parts['host'] : null;
        if (null === $host && null === $configuredHost) {
            return $configured;
        }

        $configuredScheme = (is_array($parts) && isset($parts['scheme'])) ? $parts['scheme'] : 'https';
        $finalScheme = $scheme ?? $configuredScheme;

        if (null !== $host) {
            // The request host already carries its port when non-standard.
            return $finalScheme . '://' . $host;
        }

        $port = (is_array($parts) && isset($parts['port'])) ? ':' . $parts['port'] : '';
        return $finalScheme . '://' . $configuredHost . $port;
    }

    /**
     * Resolves the public host from the request, honouring a forwarding proxy
     * (X-Forwarded-Host), else the Host header. Returns null when nothing
     * usable is present. Validated as a bare host[:port] to guard against
     * header injection.
     */
    private static function detectHost(): ?string
    {
        $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '');
        $candidate = '' !== $forwarded
            ? trim(explode(',', $forwarded)[0])
            : trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

        if ('' === $candidate) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $candidate)) {
            return null;
        }
        return $candidate;
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

    private static function dispatchOauthUnknownEndpoint(string $path): never
    {
        rex_response::cleanOutputBuffers();
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'not_found',
            'error_description' => 'Unknown OAuth endpoint: ' . $path,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
