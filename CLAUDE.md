# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ai_platform` is a REDAXO 5.18+ addon that gives the CMS a unified LLM layer. It wraps **Symfony AI 0.6** (`symfony/ai-platform`, `symfony/ai-agent`, plus the OpenAI / Anthropic / Gemini / Ollama bridges) and exposes:

1. A backend UI for managing **profiles** (one profile = one use case = one type + provider + model + per-type options).
2. A PHP service API (`rex_ai_platform_service`) for text generation, image generation, image understanding, and tool-using agents.
3. An **MCP (Model Context Protocol) HTTP server** at `index.php?rex-api-call=ai_mcp` that other MCP clients (Claude Desktop, Cursor, Windsurf, Claude Code CLI) connect to via `mcp-remote`.

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

### Core class: `rex_ai_platform_service` (`lib/rex_ai_platform_service.php`)

Singleton accessed via `rex_ai_platform_service::getInstance()`. It owns two in-request caches: `$profileCache` (DB row by id) and `$platformCache` (built `PlatformInterface` by profile id). All higher-level helpers (`generateText`, `understandImage`, `generateImage`, `createAgent`) resolve a profile, build a `PlatformInterface` via the matching `Symfony\AI\Platform\Bridge\*\PlatformFactory`, and invoke it.

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
| `mcp_enabled`                         | 0/1 — gates the MCP HTTP endpoint                                |
| `mcp_token`                           | Bearer token for the MCP endpoint; generated in `install.php`    |
| `mcp_description`                     | Sent as `instructions` in MCP `initialize` response              |

### MCP server stack (`lib/rex_ai_mcp_*.php`)

Implements a JSON-RPC 2.0 / Streamable-HTTP MCP server (protocol version `2025-03-26`) plus the MCP authorization layer (OAuth 2.1, planned phase 2). Split across five classes:

| Class | Responsibility |
|---|---|
| `rex_ai_mcp_router` | Matches `REQUEST_URI` against the route table (`/mcp`, `/.well-known/oauth-*`, `/oauth/*`) on the `PACKAGES_INCLUDED` extension point. Always `exit`s on match. |
| `rex_ai_mcp_server` | JSON-RPC handler: `initialize`, `tools/list`, `tools/call`, `ping`. Calls the authenticator once per request and passes the resulting context to tool handlers. |
| `rex_ai_mcp_authenticator` | Phase 1: returns an anonymous context for every request. Phase 2: validates OAuth bearer tokens, resolves YCom user + scopes. Also builds the `WWW-Authenticate` challenge header on 401. |
| `rex_ai_mcp_context` | Value object handed to tool handlers — `getYcomUser()`, `hasScope()`, `getAuthMode()`. Anonymous context for public tools. |
| `rex_ai_mcp_tool` | Tool definition with `public` flag, `requiredScopes`, and a `(array $arguments, rex_ai_mcp_context $context)` handler signature. |

`rex_api_ai_mcp` still exists but is now a thin deprecated shim that simply delegates to `rex_ai_mcp_server` for clients that still hit `?rex-api-call=ai_mcp`.

**Auth model:**

- Tools declare `public: true` → callable without authentication. `redaxo_status` is the canonical public tool (Monitoring use case).
- Tools without `public: true` → require an authenticated context. Until OAuth lands in phase 2, every authenticated call returns 401 with `WWW-Authenticate: Bearer realm="MCP", resource="https://<host>/.well-known/oauth-protected-resource"` so MCP clients know where to start the OAuth flow.
- The static bearer token (`ai_platform/mcp_token` in `rex_config`) was **removed** in 1.0.0-beta2. `install.php` actively deletes the key on (re-)install.

**Tools come from a single extension point**: `AI_PLATFORM_MCP_TOOLS` with subject `array<string, rex_ai_mcp_tool>`. Any addon (or this addon's own `boot.php`, which registers `redaxo_status`) pushes new entries keyed by tool name. `tools/list` filters by `isCallableBy($context)` — anonymous callers only see public tools.

A second extension point, `AI_PLATFORM_AGENT_TOOLS`, feeds `rex_ai_platform_service::createAgent()`. Subjects there are **Symfony AI Tool objects**, not `rex_ai_mcp_tool` — the two systems are intentionally separate because their tool-object shapes differ.

### Routing without rewrites

The router hooks into `PACKAGES_INCLUDED` (frontend only, `!rex::isBackend()`) and matches `REQUEST_URI` against a small path table. This avoids touching the root `.htaccess` but means the router runs on **every** frontend request. The match cost is one `parse_url` + a handful of string comparisons — fine, but keep the route table small.

The router routes:
- `POST /mcp` → `rex_ai_mcp_server::handle()`
- `GET /.well-known/oauth-{protected-resource,authorization-server}` → static discovery JSON
- `GET /oauth/{authorize,token,register}` → 501 (phase 2 stubs)

### Backend UI (`pages/`)

Four subpages: `profiles` (CRUD via `rex_form` + `rex_list`), `settings` (default-profile dropdowns), `mcp` (toggle / token / description / endpoint URL display), `docs` (renders the README). `index.php` is just the dispatcher that includes the active subpage via `rex_be_controller::includeCurrentPageSubPath()`.

`pages/profiles.php` also wires up an in-page "Test connection" AJAX button when editing a profile. The handler is `rex_api_ai_test` (`$published = false`, admin-only). Image-generation profiles **cannot be fully tested** — for OpenAI the test endpoint falls back to a GET against `/v1/models/<model>` to verify the key; for the other providers it returns a "test manually via a text profile with the same key" message. Mirror this pattern if you add a new image-only provider.

### Frontend asset: `assets/profiles.js`

Drives the provider/type-aware field visibility on the profile edit form. It reads `#ai-type-select` and `#ai-provider-select` and toggles wrapper divs around each form field. There's no build step — edit the JS directly. `boot.php` only loads it when `rex::isBackend() && rex::getUser()`.

## Things to know before changing code

- `composer.lock` and `vendor/` are committed. Bump deps with `composer update`, run a manual test (profile create + connection test + MCP `tools/list` call), then commit the refreshed `composer.lock` + `vendor/` together — never split them across commits.
- Symfony AI 0.6 is **alpha-ish**; pinning is `^0.6`. Breaking changes between minor 0.x bumps are likely — update with care and re-test all four `getPlatform()` branches.
- When `rex_api_function` endpoints `exit` mid-flow (both `rex_api_ai_mcp` and `rex_api_ai_test` do this), they bypass REDAXO's normal response pipeline. Always `rex_response::cleanOutputBuffers()` first and never rely on `rex_api_result` for the response body.
- `rex_ai_platform_service::getProfile()` filters by `status = 1`; inactive profiles are invisible to all callers. That's intentional — keep it that way unless adding an explicit "include inactive" parameter.
- Profile IDs are referenced by the three `default_*_profile` config keys, but **there is no FK or cleanup** when a profile is deleted. After delete, the config still points at the gone id and the next API call throws `rex_exception('No default AI profile configured for: …')`. If you touch the delete handler in `pages/profiles.php`, consider clearing matching config keys.
