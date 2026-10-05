<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\OAuth;

use rex_article;
use rex_fragment;
use rex_i18n;
use rex_response;
use rex_ycom_auth;
use rex_ycom_config;
use rex_ycom_user;

/**
 * Handler for `/oauth/authorize`.
 *
 * Drives the user-facing leg of the OAuth 2.1 Authorization-Code-with-PKCE
 * flow. Runs in three phases for the same URL:
 *
 *  1. GET  with no YCom session  →  redirect to YCom's login article and let
 *                                   YCom handle the sign-in entirely (see
 *                                   `redirectToLogin()`)
 *  2. POST `_action=login`       →  rex_ycom_auth::login(...) attempt; on
 *                                   success fall through to consent; on
 *                                   failure re-render the login form with
 *                                   an error message
 *  3. GET / POST with session    →  render consent screen showing the
 *                                   effective scopes (intersection of
 *                                   requested scopes and the user's
 *                                   group-mapped scopes)
 *  4. POST `_action=consent`     →  if "allow": create authorization_code
 *                                   in TokenStore and
 *                                   redirect to client redirect_uri;
 *                                   if "deny": redirect with
 *                                   error=access_denied
 *
 * Security rules per OAuth 2.1:
 *   - Unknown client_id / redirect_uri mismatch is shown as a plain error
 *     page; we MUST NOT redirect somewhere we cannot trust.
 *   - All other errors redirect back to the registered redirect_uri with
 *     `?error=...&state=...` so the client sees them.
 */
final class AuthorizationEndpoint
{
    public static function dispatch(): never
    {
        rex_response::cleanOutputBuffers();

        // YCom registers its auth-init handler on PACKAGES_INCLUDED too.
        // Our router fires inside that same extension point and we don't
        // know which handler ran first, so we force-init here. Without
        // this, rex_ycom_auth::getUser() returns null on requests after a
        // successful login because the session UID hasn't been rehydrated.
        if (class_exists('rex_ycom_auth')) {
            rex_ycom_auth::init();
        }

        $params = self::collectParams();
        $clientId = trim((string) ($params['client_id'] ?? ''));
        $redirectUri = trim((string) ($params['redirect_uri'] ?? ''));

        // --- Security gate: client + redirect_uri must be known and registered
        if ('' === $clientId) {
            self::renderError('invalid_request', 'client_id is required');
        }
        $client = ClientStore::findByClientId($clientId);
        if (null === $client) {
            self::renderError('invalid_client', 'Unknown client_id');
        }
        if (ClientStore::isExpired($client)) {
            self::renderError('invalid_client', 'Client registration has expired, please register again');
        }
        if ('' === $redirectUri || !ClientStore::redirectUriMatches($client, $redirectUri)) {
            self::renderError('invalid_request', 'redirect_uri does not match a registered URI for this client');
        }

        // From here on, errors redirect back to the client.
        $state = (string) ($params['state'] ?? '');
        $responseType = (string) ($params['response_type'] ?? '');
        if ('code' !== $responseType) {
            self::redirectWithError($redirectUri, 'unsupported_response_type', 'response_type must be "code"', $state);
        }

        $codeChallenge = trim((string) ($params['code_challenge'] ?? ''));
        $codeChallengeMethod = strtoupper(trim((string) ($params['code_challenge_method'] ?? 'S256')));
        if ('' === $codeChallenge) {
            self::redirectWithError($redirectUri, 'invalid_request', 'code_challenge is required (PKCE is mandatory)', $state);
        }
        if ('S256' !== $codeChallengeMethod) {
            self::redirectWithError($redirectUri, 'invalid_request', 'Only S256 code_challenge_method is supported', $state);
        }

        $requestedScopes = self::parseScopes((string) ($params['scope'] ?? ''));

        // --- User session
        //
        // Signing in is YCom's job, not this endpoint's. All that is checked here
        // is whether a session exists; how it came about is deliberately none of
        // our business, so SAML, CAS, YCom's own OAuth2, a login token and the
        // ordinary form all work without this class knowing about any of them.
        $user = self::currentYcomUser();
        $action = (string) ($params['_action'] ?? '');

        if (null === $user) {
            self::redirectToLogin($params);
        }

        // --- Effective scopes (intersection of requested ↔ user-granted)
        $userScopes = ScopeRegistry::resolveScopesForYcomUser($user->getId());
        if ([] === $requestedScopes) {
            $effective = $userScopes;
        } else {
            $effective = array_values(array_intersect($requestedScopes, $userScopes));
        }

        // --- Consent decision
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? 'GET') && 'consent' === $action) {
            $decision = (string) ($params['decision'] ?? '');
            if ('allow' !== $decision) {
                self::redirectWithError($redirectUri, 'access_denied', 'User denied the request', $state);
            }

            $code = TokenStore::issueAuthorizationCode(
                $clientId,
                $user->getId(),
                $effective,
                $codeChallenge,
                $codeChallengeMethod,
                $redirectUri,
            );

            $query = ['code' => $code];
            if ('' !== $state) {
                $query['state'] = $state;
            }
            // Force "&" as the separator: the web SAPI's php.ini may set
        // arg_separator.output to "&amp;", which would corrupt the redirect
        // query (state= becomes amp;state=) and break the OAuth callback.
        self::redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query, '', '&'));
        }

        self::renderConsent($client, $user, $requestedScopes, $effective, $userScopes, $params);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function collectParams(): array
    {
        $get = $_GET ?? [];
        $post = $_POST ?? [];
        return array_merge($get, $post);
    }

    private static function currentYcomUser(): ?rex_ycom_user
    {
        if (!class_exists('rex_ycom_auth')) {
            return null;
        }
        $user = rex_ycom_auth::getUser();
        return $user instanceof rex_ycom_user ? $user : null;
    }

    /**
     * @return list<string>
     */
    private static function parseScopes(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }
        $scopes = preg_split('/\s+/', trim($raw)) ?: [];
        return array_values(array_filter($scopes, static fn ($s) => is_string($s) && '' !== $s));
    }

    private static function redirectWithError(string $redirectUri, string $error, string $description, string $state): never
    {
        $query = ['error' => $error, 'error_description' => $description];
        if ('' !== $state) {
            $query['state'] = $state;
        }
        // Force "&" as the separator: the web SAPI's php.ini may set
        // arg_separator.output to "&amp;", which would corrupt the redirect
        // query (state= becomes amp;state=) and break the OAuth callback.
        self::redirect($redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($query, '', '&'));
    }

    private static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    private static function renderError(string $code, string $message, int $status = 400): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');

        $fragment = new rex_fragment();
        $fragment->setVar('code', $code, false);
        $fragment->setVar('message', $message, false);

        echo self::renderPage(rex_i18n::msg('ai_platform_oauth_error_title'), $fragment->parse('ai_platform/oauth/error.php'));
        exit;
    }

    /**
     * Sends an anonymous visitor to YCom's login article and comes back when
     * there is a session.
     *
     * **Signing in belongs to YCom, and this endpoint owns none of it.** There used
     * to be a password form here, calling `rex_ycom_auth::login()` with a name and a
     * password — the database check and nothing else. Two things were wrong with
     * that. An installation authenticating against SAML, CAS or an external OAuth2
     * provider has no local password to type, so the form was a dead end that looked
     * functional. And `rex_ycom_auth::login()` does not run YCom's injections: those
     * are evaluated in `init()`, whose return value this endpoint discards on purpose
     * because an OAuth endpoint must not be redirected away mid-flow. OTP, a forced
     * password change and a terms-of-use screen were therefore all skipped, and the
     * MCP token was issued without them — the second factor protected the frontend
     * but not the MCP access.
     *
     * The article is YCom's own `article_id_login`. It is deliberately **not** a
     * setting of this addon: a second field next to YCom's would be a second answer
     * to one question, and the two would eventually disagree.
     *
     * The return trip rides on `returnTo`, which is what `ycom_auth_returnto` reads
     * (`rex_request('returnTo', 'string')`), and YCom validates it against the
     * yrewrite domains in `rex_ycom_auth::getReturnTo()`. The path comes from the
     * actual request rather than a constant, so it stays right if the endpoint is
     * ever reached under a different prefix; the query is rebuilt from the parameters
     * because a POST has none of its own left in the URL.
     *
     * @param array<string, mixed> $params
     */
    private static function redirectToLogin(array $params): never
    {
        // Without YCom there is no identity to authorize against at all, and without
        // a login article there is nowhere to send the visitor. Both are setup
        // mistakes on the server side, so they are reported as such instead of
        // silently failing: a 500 and a message naming what is missing beats a blank
        // screen or a redirect into a 404.
        if (!class_exists('rex_ycom_auth')) {
            self::renderError('login_unavailable', rex_i18n::rawMsg('ai_platform_oauth_login_no_ycom'), 500);
        }

        $articleId = (int) rex_ycom_config::get('article_id_login');
        if ($articleId <= 0 || null === rex_article::get($articleId)) {
            self::renderError('login_unavailable', rex_i18n::rawMsg('ai_platform_oauth_login_no_article'), 500);
        }

        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            $path = '/oauth/authorize';
        }

        // The form's own control fields have no business in a URL that is handed to
        // another page and written to an access log.
        $carry = $params;
        foreach (['_action', 'decision'] as $drop) {
            unset($carry[$drop]);
        }
        $carry = array_filter($carry, static fn ($value): bool => is_scalar($value));

        // Explicit "&": the web SAPI's arg_separator.output may be "&amp;", which
        // would turn state= into amp;state= on the way back.
        $query = http_build_query($carry, '', '&');
        $returnTo = $path . ('' !== $query ? '?' . $query : '');

        self::redirect(rex_getUrl($articleId, '', ['returnTo' => $returnTo], '&'));
    }

    /**
     * @param array<string, mixed> $client
     * @param list<string> $requested
     * @param list<string> $effective
     * @param list<string> $userScopes
     * @param array<string, mixed> $params
     */
    private static function renderConsent(array $client, rex_ycom_user $user, array $requested, array $effective, array $userScopes, array $params): never
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        $fragment = new rex_fragment();
        $fragment->setVar('clientName', (string) $client['client_name'], false);
        $fragment->setVar('userEmail', (string) $user->getValue('email'), false);
        $fragment->setVar('effective', $effective, false);
        $fragment->setVar('omitted', array_values(array_diff($requested, $effective)), false);
        $fragment->setVar('descriptions', ScopeRegistry::allScopes(), false);
        $fragment->setVar('hiddenFields', self::hiddenInputs($params, ['decision', '_action']), false);

        echo self::renderPage(rex_i18n::msg('ai_platform_oauth_consent_title'), $fragment->parse('ai_platform/oauth/consent.php'));
        exit;
    }

    /**
     * Renders the OAuth query params as hidden form inputs so the form
     * submission preserves them. Skips the named keys that are set
     * explicitly by the form template (decision, _action).
     *
     * @param array<string, mixed> $params
     * @param list<string> $skip
     */
    private static function hiddenInputs(array $params, array $skip): string
    {
        $skipMap = array_flip($skip);
        $html = '';
        foreach ($params as $key => $value) {
            if (isset($skipMap[$key])) {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $html .= '<input type="hidden" name="' . rex_escape((string) $key) . '" value="' . rex_escape((string) $value) . '">';
        }
        return $html;
    }

    /**
     * Wraps the inner body in the shared HTML shell. Both the shell and the
     * inner bodies live in overridable fragments under
     * `fragments/ai_platform/oauth/` — a project can override them by placing
     * a file with the same path in a later-loading fragments directory.
     */
    private static function renderPage(string $title, string $body): string
    {
        $fragment = new rex_fragment();
        $fragment->setVar('title', $title, false);
        $fragment->setVar('content', $body, false);
        return $fragment->parse('ai_platform/oauth/page.php');
    }
}
