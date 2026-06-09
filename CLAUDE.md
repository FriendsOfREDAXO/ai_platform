# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ai_platform` is a REDAXO 5.18+ addon that gives the CMS a unified LLM layer. It wraps **Symfony AI 0.6** (`symfony/ai-platform`, `symfony/ai-agent`, plus the OpenAI / Anthropic / Gemini / Ollama bridges) and exposes:

1. A backend UI for managing **profiles** (one profile = one use case = one type + provider + model + per-type options).
2. A PHP service API (`FriendsOfRedaxo\AiPlatform\Service`) for text generation, image generation, image understanding, and tool-using agents.
3. An **MCP (Model Context Protocol) HTTP server** at `/mcp` that other MCP clients (Claude Desktop, Cursor, Windsurf, Claude Code CLI) connect to via `mcp-remote`.

The repo is a standalone addon checked out directly into `redaxo/src/addons/ai_platform/` of a REDAXO install. The outer REDAXO repo (one level above `redaxo/src/addons/`) carries its own `CLAUDE.md` with the core/test commands — those apply when running checks against the whole CMS.

## Development workflow

```bash
# Refresh / install Symfony AI deps. vendor/ and composer.lock ARE committed
# (FoR-Addon convention so end users don't need composer on the server)
composer install --no-dev

# After changing class files in lib/, re-create the classmap so REDAXO sees new classes
composer dump-autoload

# After changing package.yml / install.php (schema), reinstall the addon in the REDAXO
# backend (System > AddOns > ai_platform > Reinstall) so install.php runs again
```

There is **no** test suite, linter, or `composer check` script in this addon. Static analysis and code style come from the outer REDAXO project's tooling (see the root CLAUDE.md): run `composer cs` / `composer phpstan` from the REDAXO project root.

`vendor/` and `composer.lock` are committed — this is the FriendsOfREDAXO convention so the AddOn-Installer download is self-contained (end users don't run composer on their server). The publish workflow in `.github/workflows/publish-to-redaxo.yml` re-runs `composer install --no-dev` before packaging the release, so a forgotten lock refresh on `main` will still get the right vendor tree on release.

The addon ships its full `vendor/` tree to production — it is NOT auto-loaded by REDAXO core. The classmap in `composer.json` only covers `lib/`; Symfony AI is loaded via the standard `vendor/autoload.php` that REDAXO addons pick up automatically when present.

## Architecture

### Core class: `FriendsOfRedaxo\AiPlatform\Service` (`lib/Service.php`)

Singleton accessed via `FriendsOfRedaxo\AiPlatform\Service::getInstance()`. It owns two in-request caches: `$profileCache` (DB row by id) and `$platformCache` (built `PlatformInterface` by profile id). All higher-level helpers (`generateText`, `understandImage`, `generateImage`, `createAgent`) resolve a profile, build a `PlatformInterface` via the matching `Symfony\AI\Platform\Bridge\*\PlatformFactory`, and invoke it.

Provider mapping is a `match` on `$profile['provider']` — adding a new provider means:
1. Adding it to `getProviders()` and `getModelSuggestions()`,
2. Adding a case in `getPlatform()` to instantiate the right `PlatformFactory`,
3. Updating the API-key visibility logic in `assets/profiles.js` (Ollama is the special case that hides the API-key field and shows Base-URL),
4. Possibly the test endpoint in `lib/rex_api_ai_test.php`.

`getProfileOptions()` is the central place that translates a stored profile into provider-specific invoke options. **Important quirk**: OpenAI's Responses API uses the key `max_output_tokens`, every other provider uses `max_tokens`. The method already branches on this — don't "fix" it.

### Profiles (DB table `rex_ai_profile`)

Schema is defined entirely in `install.php` via `rex_sql_table::ensureColumn()` (no `.sql` file). One row per profile. The `type` column constrains which other columns are relevant:

- `text` → `temperature`, `max_tokens`, `system_prompt`
- `image_generation` → `image_size`, `image_quality`, `image_style` (last two are DALL-E only; hidden by `profiles.js` for non-OpenAI)
- `image_understanding` → `temperature`, `max_tokens`, `detail_level` (note: `detail_level` is persisted but not currently sent to providers — comment in README.md§Bildverstaendnis)

The backend form (`pages/profiles.php`) always renders **all** type fields; `assets/profiles.js` shows/hides them based on the selected type and provider. There's no server-side gating, so values for hidden fields stay in the DB if previously set.

`install.php` is idempotent (uses `ensureColumn`) but also explicitly `removeColumn`s three legacy columns (`model_text`, `model_image_generation`, `model_image_understanding`) — the schema used to be one row across all three types before being split into per-type profiles. Don't reintroduce those columns.

### Config (`rex_config` namespace `ai_platform`)

| Key                                   | Purpose                                                          |
|---------------------------------------|------------------------------------------------------------------|
| `default_text_profile`                | Profile id used by `generateText()` / `createAgent('text')`      |
| `default_image_generation_profile`    | Profile id used by `generateImage()`                             |
| `default_image_understanding_profile` | Profile id used by `understandImage()`                           |
| `default_embedding_profile`           | Profile id used by `generateEmbedding()`                         |
| `mcp_enabled`                         | 0/1 — gates the MCP HTTP endpoint                                |
| `mcp_description`                     | Sent as `instructions` in MCP `initialize` response              |
| `mcp_require_auth`                    | 0/1 — when 1, `FriendsOfRedaxo\AiPlatform\Mcp\Server::handle()` challenges anonymous requests with 401 on every method (incl. `initialize`/`tools/list`), forcing the client's OAuth flow. Needed for protected tools to surface in clients that only start OAuth on a 401 (e.g. Claude Desktop). 0 = anonymous clients allowed (public tools only). |
| `mcp_disabled_tools`                  | JSON list of tool names switched off in the backend. Opt-out: `FriendsOfRedaxo\AiPlatform\Mcp\Server::isToolEnabled()` hides them from `tools/list` and rejects them in `tools/call`. New tools default to enabled. |
| `oauth_client_lifetime_days`           | Days after which an OAuth client registration expires (based on `createdate`). 0 = never. `FriendsOfRedaxo\AiPlatform\OAuth\ClientStore::isExpired()` is enforced in the authorize + token endpoints (expired → `invalid_client`, which makes MCP clients re-register via DCR). |

The pre-1.0 `mcp_token` key was removed in 1.0.0-beta2; `install.php` actively deletes it on (re-)install. OAuth state lives in dedicated tables, not in `rex_config`.

### OAuth tables (`rex_ai_oauth_*`, `rex_ai_scope_mapping`)

Four tables backing the OAuth 2.1 layer:

| Table | Purpose |
|---|---|
| `rex_ai_oauth_client` | Client registry. `client_secret_hash` is null on public clients (PKCE only), `password_hash()` on confidential clients. `created_by_dcr` flags DCR-registered clients. UNIQUE on `client_id`. |
| `rex_ai_oauth_authorization_code` | One-shot codes. `code_hash` is SHA-256. `code_challenge` + `code_challenge_method` (S256 only). `used_at` is the consumption marker. UNIQUE `code_hash`, index `expires_at`. |
| `rex_ai_oauth_token` | Access + refresh tokens in one table differentiated by `type`. `parent_token_id` links refresh→access for joint revocation on rotation. UNIQUE `token_hash`, indexes `(type, expires_at)` and `ycom_user_id`. |
| `rex_ai_scope_mapping` | YCom-group (UNIQUE) → JSON scope list. |

All sensitive material (codes, access tokens, refresh tokens) stored as SHA-256 hex. Plaintext returned only once at issuance time.

### MCP + OAuth class stack (`lib/rex_ai_*.php`)

Implements a JSON-RPC 2.0 / Streamable-HTTP MCP server (protocol version `2025-03-26`) and a full OAuth 2.1 authorization layer with PKCE + DCR + Refresh Tokens.

| Class | Responsibility |
|---|---|
| `FriendsOfRedaxo\AiPlatform\Mcp\Router` | Single entry point. Hooks `PACKAGES_INCLUDED` (frontend only) and matches `REQUEST_URI` against the route table; dispatches to the right endpoint or returns 404 / 405. `exit`s on match. `baseUrl()` derives scheme **and host** from the actual request (`X-Forwarded-Proto` / `X-Forwarded-Host` honoured), falling back to `rex::getServer()` — so discovery/issuer/redirect URLs are correct on HTTPS-served sites and behind a reverse proxy or tunnel (ngrok/Cloudflare). Without this, a tunneled request advertises the internal host and the client's DCR/authorize/token calls hit an unreachable host. |
| `FriendsOfRedaxo\AiPlatform\Mcp\Server` | JSON-RPC handler: `initialize`, `tools/list`, `tools/call`, `ping`. Calls the authenticator once per request, passes the resulting context to tool handlers, converts `FriendsOfRedaxo\AiPlatform\Mcp\InvalidTokenException` and `FriendsOfRedaxo\AiPlatform\Mcp\AuthRequiredException` into RFC-6750 challenges. |
| `FriendsOfRedaxo\AiPlatform\Mcp\Authenticator` | Resolves the auth context: no Authorization header → anonymous; valid OAuth bearer → authenticated context with `ycomUserId` + `scopes` from `rex_ai_oauth_token`; invalid token → `FriendsOfRedaxo\AiPlatform\Mcp\InvalidTokenException` (server converts to 401 + `error="invalid_token"`). |
| `FriendsOfRedaxo\AiPlatform\Mcp\Context` | Value object: `getYcomUser()`, `hasScope()`, `hasAllScopes()`, `getAuthMode()`, `getClientId()`. |
| `FriendsOfRedaxo\AiPlatform\Mcp\Tool` | Tool definition with `public` + `requiredScopes` parameters and a `(array $arguments, FriendsOfRedaxo\AiPlatform\Mcp\Context $context)` handler signature. |
| `FriendsOfRedaxo\AiPlatform\OAuth\AuthorizationEndpoint` | `/oauth/authorize`. One method, three states: login form → consent screen → redirect with code (or `access_denied`). Calls `rex_ycom_auth::init()` explicitly because YCom's own init runs on the same `PACKAGES_INCLUDED` event and order isn't stable. The HTML (shell, login, consent, error) lives in overridable fragments under `fragments/ai_platform/oauth/` — the endpoint only sets data and calls `$fragment->parse()`; a project overrides the look via `project/fragments/ai_platform/oauth/*.php`. In those fragments do NOT re-escape `rex_i18n::msg()` output (already `html_simplified`-escaped), only raw data. |
| `FriendsOfRedaxo\AiPlatform\OAuth\TokenEndpoint` | `/oauth/token`. `grant_type=authorization_code` validates redirect_uri match, client_id match, and PKCE S256 challenge. `grant_type=refresh_token` rotates and revokes both old tokens jointly via `parent_token_id`. Standard OAuth error envelope with 400 / 401 statuses. |
| `FriendsOfRedaxo\AiPlatform\OAuth\DcrEndpoint` | `/oauth/register`. RFC-7591 Dynamic Client Registration. Auto-approves public clients (PKCE only, no secret). Validates absolute URIs and that `token_endpoint_auth_method` is `"none"`. |
| `FriendsOfRedaxo\AiPlatform\OAuth\ClientStore` | CRUD for clients: create (public/confidential), findByClientId, findAll, deleteById, verifySecret (`password_verify`), redirectUriMatches (exact-match list). |
| `FriendsOfRedaxo\AiPlatform\OAuth\TokenStore` | Issues + consumes authorization codes (single-use, expiry-aware), issues access+refresh pairs, rotates refresh tokens with joint revocation, revokes all tokens for a client. Lifetimes as class constants: `ACCESS_TOKEN_TTL=3600`, `REFRESH_TOKEN_TTL=30d`, `CODE_TTL=600`. |
| `FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry` | No built-in scopes (`builtInScopes()` returns `[]`; the `SCOPE_TOOLS_*` constants are deprecated, unenforced). All selectable scopes come from third addons via the `AI_PLATFORM_OAUTH_SCOPES` extension point. Group↔scope CRUD; `resolveScopesForYcomUser()` aggregates via `rex_ycom_user::getGroups()`. |

The legacy `?rex-api-call=ai_mcp` endpoint (class `rex_api_ai_mcp`) was removed — `/mcp` is the only MCP entry point now. Unknown `/oauth/*` paths return 404 via `FriendsOfRedaxo\AiPlatform\Mcp\Router::dispatchOauthUnknownEndpoint()`.

**Auth model:**

- Tools declare `public: true` → callable without authentication. `redaxo_status` is the canonical public tool.
- Tools without `public: true` → require an authenticated context. Invalid / missing tokens trigger a 401 + `WWW-Authenticate: Bearer realm="MCP", resource="…", error="invalid_token"` so MCP clients automatically pick up the OAuth flow.
- Scopes resolve via `YCom user → YCom groups → rex_ai_scope_mapping`. There are no built-in scopes; tools are gated only by their `public` flag and their own `requiredScopes`. Consumer addons announce scopes via `AI_PLATFORM_OAUTH_SCOPES` and declare them on tools via `requiredScopes`.

**Tools** come from `AI_PLATFORM_MCP_TOOLS` with subject `array<string, FriendsOfRedaxo\AiPlatform\Mcp\Tool>`. `tools/list` filters by `isCallableBy($context)` so anonymous callers only see public tools. `AI_PLATFORM_AGENT_TOOLS` feeds `FriendsOfRedaxo\AiPlatform\Service::createAgent()`; subjects there are **Symfony AI Tool objects**, not `FriendsOfRedaxo\AiPlatform\Mcp\Tool` — the two systems are intentionally separate because their tool-object shapes differ.

### Routing without rewrites

The router hooks into `PACKAGES_INCLUDED` (frontend only, `!rex::isBackend()`) and matches `REQUEST_URI` against a small path table. This avoids touching the root `.htaccess` but means the router runs on **every** frontend request. The match cost is one `parse_url` + a handful of string comparisons.

Live routes:
- `POST /mcp` → `FriendsOfRedaxo\AiPlatform\Mcp\Server::handle()` (returns 405 + `Allow: POST` for other methods)
- `GET /.well-known/oauth-{protected-resource,authorization-server}` → static discovery JSON, scheme derived from the actual request
- `GET/POST /oauth/authorize` → `FriendsOfRedaxo\AiPlatform\OAuth\AuthorizationEndpoint::dispatch()` (login screen / consent screen / decision)
- `POST /oauth/token` → `FriendsOfRedaxo\AiPlatform\OAuth\TokenEndpoint::dispatch()` (Code+PKCE or Refresh)
- `POST /oauth/register` → `FriendsOfRedaxo\AiPlatform\OAuth\DcrEndpoint::dispatch()` (DCR auto-approve)
- any other `/oauth/*` → 404 `not_found`

**Important pitfall:** YCom's auth-init handler runs on `PACKAGES_INCLUDED` too, and the order isn't stable. Without an explicit `rex_ycom_auth::init()` call at the top of the authorize dispatch, `getUser()` returns null on every follow-up request after a successful login because the session UID hasn't been rehydrated from session yet.

### Backend UI (`pages/`)

Top-level subpages: `profiles`, `settings`, `mcp`, `docs`. The MCP subpage is itself nested:

```
ai_platform/
├── profiles/                     (LLM provider profile CRUD)
├── settings/                     (default profile dropdowns)
├── mcp/
│   ├── settings/                 (mcp_enabled, mcp_description, endpoint URLs, auth/scopes banner, tool list)
│   ├── oauth-clients/            (manual client CRUD + DCR-registered client list)
│   └── scope-mapping/            (per-YCom-group multi-select of all known scopes)
└── docs/                         (renders README.md via rex_markdown)
```

`pages/index.php` is just the dispatcher that includes the active subpage via `rex_be_controller::includeCurrentPageSubPath()`. The nesting is implemented through `package.yml`'s `subpages:` tree, which lets REDAXO resolve `?page=ai_platform/mcp/settings` → `pages/mcp/settings.php`.

`pages/profiles.php` also wires up an in-page "Test connection" AJAX button when editing a profile. The handler is `rex_api_ai_test` (`$published = false`, admin-only). Image-generation profiles **cannot be fully tested** — for OpenAI the test endpoint falls back to a GET against `/v1/models/<model>` to verify the key; for the other providers it returns a "test manually via a text profile with the same key" message. Mirror this pattern if you add a new image-only provider.

### Frontend asset: `assets/profiles.js`

Drives the provider/type-aware field visibility on the profile edit form. It reads `#ai-type-select` and `#ai-provider-select` and toggles wrapper divs around each form field. There's no build step — edit the JS directly. `boot.php` only loads it when `rex::isBackend() && rex::getUser()`.

## Things to know before changing code

- `composer.lock` and `vendor/` are committed. Bump deps with `composer update`, run a manual test (profile create + connection test + MCP `tools/list` call), then commit the refreshed `composer.lock` + `vendor/` together — never split them across commits.
- Symfony AI 0.6 is **alpha-ish**; pinning is `^0.6`. Breaking changes between minor 0.x bumps are likely — update with care and re-test all four `getPlatform()` branches.
- When code `exit`s mid-flow (the `FriendsOfRedaxo\AiPlatform\Mcp\Router` routes and the `rex_api_ai_test` endpoint do this), it bypasses REDAXO's normal response pipeline. Always `rex_response::cleanOutputBuffers()` first and never rely on `rex_api_result` for the response body.
- `FriendsOfRedaxo\AiPlatform\Service::getProfile()` filters by `status = 1`; inactive profiles are invisible to all callers. That's intentional — keep it that way unless adding an explicit "include inactive" parameter.
- The MCP router runs on `PACKAGES_INCLUDED` and `exit`s on match. That bypasses REDAXO's normal request lifecycle. If you add a new route, always `rex_response::cleanOutputBuffers()` before sending anything, never call `rex_response::sendContent()`, and remember the router fires on every frontend request — keep the path table minimal.
- The OAuth tables (`rex_ai_oauth_*`, `rex_ai_scope_mapping`) are created in `install.php` via `rex_sql_table::ensure*`. Adding a column → add an `ensureColumn()` line and reinstall the addon (`bin/console package:install ai_platform`, choose reinstall).
- `FriendsOfRedaxo\AiPlatform\OAuth\TokenStore` rotates refresh tokens with joint revocation: when `rotateRefreshToken()` succeeds, **both** the old refresh and its parent access token are revoked. Don't change that — it's the replay protection.
- Reproducible test harness in `.claude/tests/` (committed; DB creds derived from `data/core/config.yml`, `BASE` overridable via env):
  - `oauth-storage-test.php` — 44 storage asserts, runs via REDAXO bootstrap + addon init
  - `oauth-token-endpoint-test.sh` — 20 token-endpoint asserts, seeds via mysql client, drives via curl
  - `oauth-authorize-test.sh` — 29 end-to-end asserts incl. browser-style login + consent + token exchange, uses `oauth-authorize-test-seed.php` for YCom user/group setup
  - Run all three before touching anything in `lib/rex_ai_mcp_*` or `lib/rex_ai_oauth_*` to lock down baseline.
- Profile IDs are referenced by the three `default_*_profile` config keys, but **there is no FK or cleanup** when a profile is deleted. After delete, the config still points at the gone id and the next API call throws `rex_exception('No default AI profile configured for: …')`. If you touch the delete handler in `pages/profiles.php`, consider clearing matching config keys.
