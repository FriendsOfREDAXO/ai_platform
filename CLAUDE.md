# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`ai_platform` is a REDAXO 5.18+ addon that gives the CMS a unified LLM layer. It wraps **Symfony AI 0.6** (`symfony/ai-platform`, `symfony/ai-agent`, plus ten provider bridges: OpenAI, Anthropic, Gemini, Ollama, Mistral, Cerebras, Scaleway, OpenRouter, Replicate and the generic OpenAI-compatible one) and exposes:

1. A backend UI for managing **profiles** (one profile = one use case = one type + provider + model + per-type options).
2. A PHP service API (`FriendsOfRedaxo\AiPlatform\Service`) for text generation, image generation, image understanding, and tool-using agents.
3. An **MCP (Model Context Protocol) HTTP server** at `/mcp` that other MCP clients (Claude Desktop, Cursor, Windsurf, Claude Code CLI) connect to via `mcp-remote`.
4. A **change request** layer: agents and addons propose content changes, an editor approves them in the backend, and only then is anything written.

The repo is a standalone addon checked out directly into `redaxo/src/addons/ai_platform/` of a REDAXO install. The outer REDAXO repo (one level above `redaxo/src/addons/`) carries its own `CLAUDE.md` with the core/test commands — those apply when running checks against the whole CMS.

## Development workflow

> **Assets are not served from `assets/`.** REDAXO copies them to
> `assets/addons/ai_platform/` at install time, and editing the source changes
> nothing for the browser. After touching `assets/styles.css`, `assets/*.js` or
> anything else in there:
>
> ```bash
> echo "y" | redaxo/bin/console package:install ai_platform
> ```
>
> This also re-runs `install.php` (which is idempotent, so that is fine) and does
> **not** run `uninstall.php` — change requests and profiles survive a reinstall.
> `change-pages-test.php` compares source and published copies and fails when they
> drift.
>
> `boot.php` stamps the asset URLs with the **published copy's mtime**, not the
> addon version. The version is right for released updates and useless here: it
> stays put across a dozen asset edits, so the reinstall refreshes the file on disk
> while the browser keeps serving the old one under an unchanged `?v=`. That looks
> exactly like a forgotten reinstall and costs the time it takes to prove it was
> not one — which it did, once. The mtime moves precisely when the copy moves.

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

Providers are not mapped here — `getProviders()`, `getModelSuggestions()` and
`getPlatform()` all delegate to `ProviderRegistry`. They stay as the public API and hold
no provider knowledge of their own.

### Providers: `FriendsOfRedaxo\AiPlatform\ProviderRegistry` (`lib/ProviderRegistry.php`)

One entry per provider, and everything about a provider inside that one entry: the label
for the select, which fields the profile form shows for it (`fields`), the default model
per type (`defaults`), the Symfony AI model catalog its suggestions come from (`catalog`),
and the closure that builds the platform (`factory`). Adding a provider is a
`composer require` for its Symfony AI bridge plus one entry — or, from another addon, one
entry appended in the `AI_PLATFORM_PROVIDERS` extension point, no fork needed. The
definitions are rebuilt on every call: caching them would freeze whichever set existed at
first access, and a provider registered later in the boot order would silently vanish.

**`assets/profiles.js` must stay free of provider names.** Field visibility, the default
model and the suggestions all arrive as JSON from `ProviderRegistry::formConfig()`, which
`pages/profiles.php` writes into a `<script type="application/json"
id="ai-provider-config">`. That is not tidiness: the same knowledge used to live in both
places, and the JS copy kept the Ollama API-key field hidden long after `getPlatform()`
supported it. The JSON sits next to the form rather than in `rex_view::setJsProperty()`
because that renders in the head (`core/layout/top.php`), which is out the door before a
page script runs.

**The model picker is a select plus an escape hatch, and the escape hatch is not
optional.** The catalogs are Symfony AI's own and stay current without work here, but they
are not exhaustive: for Ollama `llama3.2-vision` is missing entirely and `llava` carries no
`INPUT_IMAGE` capability, so both drop out of the image-understanding list — and the
generic provider has no catalog at all, because a self-hosted server can call its models
anything. A closed select would lock those out. Hence: the select is the field's *prefix* and only writes
into the `model` input, which stays the single control bound to the column; the last option
reveals that input for a name of one's own; an empty catalog hides the select entirely
rather than offering a list of one; and `syncModelSelect()` derives the select from the
input and never the other way round, so a stored name the catalog does not list survives
opening and saving the profile instead of being silently replaced by the first option.

**`TYPE_CAPABILITIES` asks for all of `all` and at least one of `any`, and both halves are
load-bearing.** `OUTPUT_TEXT` on its own also matches speech-to-text, so `whisper-1` would
appear under a text profile — hence the demand for a text-ish input. But demanding
`INPUT_MESSAGES` specifically is too narrow: OpenRouter's catalog describes its entries
with `INPUT_TEXT`, and that stricter rule left **2 of its 362 models** standing. Accepting
either keeps whisper out and OpenRouter in, and changes nothing for the four original
providers (19/14/9/19 text models before and after — the test asserts the whisper half,
the counts were measured). Note `array_intersect()` is not an option for this check: it
compares by string cast and throws on `Capability` instances.

**Base URLs are normalised**: a trailing `/v1` is stripped, because the bridges append
their own versioned path (`/v1/chat/completions` for the generic one) while every provider
documents its endpoint *with* the `/v1`. Both spellings have to reach the same URL.

**What a name outside the catalog actually does, though, depends on the provider** — and
it is not "it just works". `AbstractModelCatalog::getModel()` throws
`ModelNotFoundException` for a name it does not know, before any request is built. So the
free-text input is load-bearing for `generic` (its `FallbackModelCatalog` accepts anything)
and for providers a third addon registers with an open catalog, while for Ollama a typed
`llama3.2-vision` is refused by Symfony AI, not by us. Extending that provider means
handing it a different catalog through the extension point, not widening the form.

**`mistral`, `cerebras` and `scaleway` are the straightforward ones**: identical factory
signature (`create($apiKey, $httpClient, $modelCatalog, …)`), an OpenAI-shaped completions
API, one `api_key` field each. Their catalogs decide what the form offers — Mistral has
pixtral for vision and `mistral-embed`, Scaleway the same in miniature, Cerebras text only,
because it hosts open models for fast inference and none of them are multimodal. None of
the three does image generation.

**Two bridges validate the shape of the key before sending anything**: OpenAI wants `sk-`,
Cerebras wants `csk-`, both throwing `InvalidArgumentException` from the model client's
constructor. So a key pasted from the wrong provider surfaces as "The API key must start
with …" out of `getPlatform()`, not as a 401 from the endpoint — worth knowing when a
support question arrives, and pinned by two assertions in the test.

**`openrouter` and `replicate` are worth a word each**, because their bridges are not
equivalent in reach. OpenRouter's `PlatformFactory` is a thin wrapper around the generic
one with `baseUrl: 'https://openrouter.ai/api'`, so it is the same protocol and simply
works; its catalog carries ~360 models, which is why the model select carries
`data-live-search="true"` at all — unconditionally, in `pages/profiles.php`, since a
search box costs nothing on a short list — and why the form payload grew from 3 KB to
about 17 KB. Its
`@preset` entry is OpenRouter's placeholder for a saved preset, not a callable model — it
is left in the list (filtering it would be provider-specific logic in the registry, which
is what this refactoring removed) and the defaults make sure a new profile never lands on
it. Replicate, in contrast, is a **Llama text client** upstream: `LlamaModelClient`,
`LlamaResultConverter`, `LlamaMessageBagNormalizer` and 15 `llama-*` catalog entries. None
of the image models Replicate is known for are reachable, so its label says "nur
Llama-Modelle, nur Text" — otherwise the empty select for the other three types reads as a
bug. There is also a `ModelApiCatalog` in the OpenRouter bridge that fetches the live list;
deliberately unused, because `formConfig()` runs on every render of the profile form and
must not become an HTTP request.

The `generic` provider is `symfony/ai-generic-platform`, i.e. plain OpenAI chat
completions against a free base URL — Open WebUI, LiteLLM, vLLM, LM Studio, OpenRouter
and the like. Do **not** hand-roll a bridge for one of those: the upstream package covers
tool calls, streaming, the 401/400/429 mapping and token usage, which a minimal
`choices[0].message.content` converter does not (that was the flaw in PR #9).

**bootstrap-select is the reason the picker needs two courtesies.** be_style initialises
every `.selectpicker` on `rex:ready` and the plugin then renders its own markup once: it
does not watch the option list, so `updateModelSelect()` and `syncModelSelect()` have to
call `selectpicker('refresh')` after touching options or the value — without it the box
stays empty and looks nothing like the type and provider boxes next to it. And the plugin
hides the original `<select>` itself and wraps it in a `div.bootstrap-select`, so
visibility is toggled on that wrapper (`modelPickerBox()`); toggling the select would
toggle something already invisible. Both fall back gracefully when the plugin is absent,
which is what makes the picker testable outside a browser.

Test: `.claude/tests/provider-registry-test.php` (180 asserts) covers the registry against
the real REDAXO boot — so a label coming out as `[translate:…]` fails — and drives the
bridges through a `MockHttpClient`, which is how the base-URL normalisation, the bearer
header and Ollama's native `/api/chat` are asserted without a network. It also pins that a
default model is offered by its catalog; that assertion is what surfaced the stale
`gemini-2.0-flash-exp` and `text-embedding-004` defaults.

Test: `.claude/tests/model-picker-test.mjs` (19 asserts, plain `node`, no npm) runs
`assets/profiles.js` against a hand-rolled minimal DOM: the custom entry, the visibility
of the credential fields, the refresh calls, the wrapper-not-select toggle, and the two
cases where a stored model name has to survive being opened. Its provider payload is a
fixture on purpose — the picker's behaviour must not start failing because Symfony AI
added a model. That the real payload has that shape is asserted in the PHP test.

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

### Change requests (`lib/Change/`)

Lets a caller with read-only access propose a write. `ChangeService::propose()`
records the intent; nothing reaches the target until a backend user approves it.
The point is that the *approval* is where permissions are checked, not the
submission.

**Layering — this is the part to keep straight.** Authentication belongs to the
adapter, not the core. Whoever can call `ChangeService` is already trusted to
*ask*; what the core guarantees is that asking does not write. The api addon
checks its bearer token, a YCom login checks the group, PHP inside REDAXO is
trusted by definition. Three adapters ship: the PHP API, three agent tools via
`AI_PLATFORM_AGENT_TOOLS`, and REST routes registered with the api addon
(`lib/Api/ChangeRoutes.php`).

**The backend entry form is gone** (`pages/ai_changes.new.php`, the
`ai_changes[create]` permission, and the conditional-field JavaScript with it).
It let a person file a request by hand, and it was the second submission path —
which would have had to be kept in step with the REST routes forever. Two paths
drifting apart is how a form ends up accepting what the API refuses. Since change
requests now only arrive over REST, `ai_platform/settings` warns when the api
addon is missing: without it the feature can be switched on and then simply never
receives anything, which is a confusing state to debug from an empty inbox.

**Which adapter serves whom** — they are not alternatives, they serve callers the
others cannot reach:

| Caller | Adapter |
|---|---|
| An addon or script running inside this REDAXO | PHP API |
| An agent this CMS itself started via `Service::createAgent()` | agent tools |
| An agent outside this REDAXO holding only a bearer token | **REST** |

### Settings, and the six that were removed

Everything the addon is configured with sits on **one page**,
`ai_platform/settings` (`pages/settings.php`). The change-request settings used to
be a page of their own — first under `ai_changes`, then as a sibling tab here —
and both arrangements had the same flaw: the master switch that decides whether
the feature exists at all sat on one page while everything it governs sat on
another. An admin switching it on had to find a second page to finish, and that
second page had to carry a sentence explaining where the switch lived.

**The detail settings are only on screen while `changes_enabled` is active** — a
setting for something switched off is not information, it is an invitation to
configure what will never run. The reveal is done in `assets/changes.js`, watching
`#changes-enabled`, so flipping the switch shows them at once; a two-step "switch
on, save, now configure" is a worse form than a long one. The server sets the
initial `hidden` so a page load with the feature off does not flash them.

Both blocks carry `data-ai-changes-detail`: the fieldset inside the form, and a
wrapper around the two read-only tables that follow it. `assets/styles.css` spells
out `[data-ai-changes-detail][hidden] { display: none !important }` rather than
trusting the user-agent rule — `fieldset` carries a `display` of its own and any
theme setting it wins.

**The fields therefore stay in the DOM and in the POST, and that is the safe
half.** A hidden input submits the value it was rendered with, so saving while the
block is out of sight writes the stored values back unchanged. Conditional
rendering is the version that bites: fields that are not rendered are not in the
POST either, and a post handler reading them anyway resets the stale policy to its
default and empties the YForm allow list — no error, a security setting silently
reopened. It shipped that way briefly, guarded by a `changes_details_present`
marker; the marker went with the conditional rendering. `change-pages-test.php`
asserts both halves — the fields are present-but-hidden when off, and a save in
that state leaves both values alone.

What is left of the change settings is two decisions plus two tables. What went,
and why, because a short settings section reads like an unfinished one:

| Removed | Why |
|---|---|
| `changes_max_payload_kb` | A large payload is a long article. Refusing it only meant the proposal could not be made. |
| `changes_retention_days` | A number in a form enforced nothing — somebody still had to press a button. Now a cronjob, where the period sits next to the schedule that makes it happen. |
| `changes_batch_limit` | What a batch can do is decided by its writes, not their number. |
| `changes_max_open_per_source` | Nothing is written without a decision, so a runaway agent produced rows and no damage. The cronjob keeps volume in check. |
| `changes_allowed_modules` + `changes_allow_all_modules` | Replaced by `ChangeService::moduleExecutesPhp()` — see below. |
| API approval allow list | The scope on the token is the decision. |

**The module list is the one worth understanding.** It existed for a single
reason: a REDAXO module whose output contains `REX_VALUE[... output=php]` runs the
slice value **as PHP**. A proposal for such a module is code, not content — and
with an approval scope it is code nobody reads before it runs. Nearly every
installation has one lying around; this one ships module 19 "php - Code".

An allow list was the wrong instrument for that. It needs maintaining, it
defaults to permissive because that is what keeps the addon usable, and the one
entry that matters is the one an admin forgets. `moduleExecutesPhp()` reads the
module's own output instead (`output=php`, plus `eval(` for hand-rolled
equivalents): automatic, unforgettable, nothing to configure. It refuses
regardless of who asks — this is a property of the module, not a permission. A
project wanting such a module writable replaces the slice handler through
`AI_PLATFORM_CHANGE_HANDLERS`, which is a deliberate act in code.

What remains configurable are the two decisions a person genuinely has to make:
`changes_stale_policy` (what happens when a target moved under a pending request)
and the YForm table allow list — the latter cannot be derived from anything,
because a proposal can name any table in the database, `rex_ycom_user` included.

### Thinning old requests: `Cronjob\ThinChangeRequests`

Registered with the cronjob addon when that is present (`class_exists` guard, so
it stays an optional dependency). Takes a period in days and empties `payload`,
`payload_edited`, `snapshot_before` and `context_snapshot` of requests decided
before then. Those four columns are effectively all of the volume.

**The row itself is never deleted.** Who proposed what, against which target,
when, decided by whom through which channel — that is the record, and once API
tokens can approve their own proposals it is the only thing standing between an
automated write and a change nobody can explain. Pending requests are never
touched whatever their age: a pending request without its payload is not a
saving, it is a broken request.

### What the MCP server is for — and what it is not for

**The MCP endpoint carries a project's own content and the tools that project
chooses to offer. It is not a remote control for REDAXO's internals.** That line
is a product decision by the addon owner, not a technical limitation, and it
holds even when a REDAXO-internal function would be trivial to expose there:

- **Belongs on `/mcp`:** the customer's articles, media, datasets, search, and
  whatever domain tools that installation wants to publish — the things an
  outside assistant is supposed to see *about this website*.
- **Does not belong on `/mcp`:** CMS-internal machinery. Change requests, addon
  configuration, user administration, cache handling, module and template
  editing. A client connecting to a customer's MCP server should not find the
  CMS's own controls in `tools/list`.

So **change requests are deliberately NOT exposed over MCP**, and a future
"but it would be one line" is not a reason to change that. The channel for an
outside agent that only holds a token is **REST**, via routes this addon
registers with the api addon (see below). A project that decides otherwise for
its own installation can still register its own MCP tool calling
`ChangeService` — that is its call to make, not the addon's default.

### REST routes (`lib/Api/ChangeRoutes.php`)

Seven routes under `/api/ai_platform/changes`, registered with the api addon.
**Nothing in the api addon is modified** — `RouteCollection::registerRoute()` is
public static, `Token::getAvailableScopes()` builds the backend's scope
checkboxes from the registered routes, and `OpenAPIConfig` builds the Swagger
page from them. Both come for free.

**The route descriptions are the documentation of last resort, so they carry the
whole workflow — do not shorten them to labels.** The api addon serves them to
the caller through `GET /api/me`, filtered to the scopes that token actually
holds. That is the only thing an agent connecting from outside is guaranteed to
see: it has no skill file, no README and no way to enumerate routes otherwise.
So each of the seven opens with `AI PLATFORM CHANGE REQUESTS` and states the
things that are not guessable from a path — that a call proposes rather than
writes, that a human decides, that `/approvals` is the single exception which
does write, that new ids come back in `created`, and that every reference is
validated *at submission*, which is why structure has to be built one round per
level. `/describe` additionally spells out the whole chain, because it is the
endpoint an agent reaches first.

**The catalogue carries the module slots, and that is not decoration.** A test
handed a fresh agent nothing but `/api/me` and a task. It succeeded — every
proposal accepted first try — but it could not find out which value slot a module
reads. `writable_fields` says `value1`–`value20` exist; module 2 reads two of
them. The agent fetched the module through the api addon and parsed its HTML for
`REX_INPUT_VALUE[n]`, which only worked because that token happened to hold
`modules/get`. **A token with only the change scopes could not build a usable
slice at all.**

So `ChangePayloadBuilder::describe()` now emits a `modules` section for the slice
type: per module the slots it actually reads, labels from
`AI_PLATFORM_MODULE_FIELDS` where a project supplied them, and `executes_php` so
a caller sees *before* proposing that a module's values run as PHP and will be
refused. `ModuleFieldMap::detectSlots()` reads that from the module's own input
and output rather than a declaration — same reasoning as
`moduleExecutesPhp()`: a list has to be maintained and the entry that matters is
the one nobody updated. Both consumers (the REST catalogue and
`DescribeChangeTypesTool`) pass the section through, and
`api-changes-test.sh` asserts a module's list is narrower than all twenty — a
regression to the flat list would otherwise read as "documented".

Target field names are marked `(create)` / `(update/delete)` for the same reason:
the agent had to infer by analogy that a category create takes `parent_id` while
an update takes `category_id`.

Every parameter carries a `description` for the same reason. Three used to have
none at all (all five on `/deletions`, both transports of `/uploads`, and the
pagination on `/{id}`), which read as "no parameters" rather than
"undocumented". The `required` flags stay `false` throughout because the body is
either one proposal or a `proposals` array and a per-field flag cannot express
that — so what is genuinely needed is stated in the description text instead.

| Method | Path | Scope suffix |
|---|---|---|
| GET | `/describe` | `read` — without params the catalogue of types, operations and field names; with `type` + `target` that target's current state |
| GET | `` / `/{id}` | `requests` — own requests: the list, or one with `review_note` and situation report |
| POST | `` (prefix itself) | `propose` — one proposal or a `proposals[]` batch |
| POST | `/uploads` | `upload` — stage one file for the media pool and reserve its name |
| POST | `/deletions` | `propose_delete` — deletions, separate scope |
| POST | `/approvals` | `approve` — approve own requests unattended |
| POST | `/withdrawals` | `withdraw` — take back own pending requests |

#### Why the count is what it is — and why it went from eight to six, then seven

`BearerAuth::isAuthorized()` checks `in_array($parameters['_route'],
$token->getScopes())`. **One route is one scope**, the finest granularity
available, and the scope is not in the body. Merging two routes therefore always
merges two permissions — right where it was one permission split in two, wrong
where the split is the point. `/deletions` stays separate for exactly that
reason, and `operation: "delete"` on the propose route is refused with a pointer
to it.

Two pairs were one permission each and were merged:

| Was | Now | Why |
|---|---|---|
| `/types` + `/current` | `/describe` | Both answer "what is there" — one in general, one for a target. Both read-only, neither changes anything. |
| `/` (list) + `/{id}` | `/` with optional `{id}` | Both read-only and both filter hard on the calling token's `source_key`, so there is no situation in which you grant one and refuse the other. The `null` default on `id` is what makes the segment optional. |

That left **four privilege levels** — look, propose, offer bytes, decide — and
`/withdrawals` later added a fifth, take back, for the case an agent notices its own
misfire. Seven routes, seven scopes. **No aliases were kept for the old paths**;
`api-changes-test.sh` asserts `/types` and `/current`
answer 404. A route that still answers is a route somebody keeps using.

An agent rarely needs all seven. `propose` alone works: `ChangeService::propose()`
reads the target's current state itself and derives `base_hash` from it, so
nothing has to be fetched first. `read` + `propose` is the practical minimum;
`requests` matters once an agent should follow up on a rejection, `approve` only
when it may write unattended.

**`meta.open` and the quota that was not there.** The list response used to carry
a `quota` object with `max_open` and `remaining`, read from
`changes_max_open_per_source`. That setting was removed — nothing is written
without a decision, so a runaway source produced rows and no damage — but the
response kept announcing the limit, so a well-behaved agent could stop proposing
at a ceiling that no longer existed. Now only `open` is reported, which is a fact.
A limit that is not enforced must not be announced.

#### `POST /withdrawals` — taking a proposal back

Closes a gap that only showed up in practice: an agent that noticed its own
mistake could do nothing about it. It could only leave the misfire in an editor's
inbox and hope someone rejected it — turning an agent's error into a person's
chore, exactly the wrong way round.

**POST, not DELETE, and nothing is deleted.** The row moves to
`ChangeStatus::Withdrawn`, which is kept distinct from `Rejected` on purpose: "an
editor looked at it and said no" and "the submitter took it back" are different
facts, and only the first says something about the proposal's quality. A `DELETE`
verb would promise removal and not deliver it.

Three limits, none configurable: only the caller's own requests, only while
`pending` (once an editor approved it, the submitter no longer decides — and by
then it is usually written), and no content is ever touched. That last one is why
this route needs no policy: it is the only operation in the feature that cannot
write.

A withdrawn request stops counting against `changes_max_open_per_source`, since
`ChangeStatus::openStates()` covers only Pending and Approved. An agent that
cleans up after itself gets the room back.

#### `POST /approvals` — the one route that writes

Six of the seven routes end in a proposal or a staged file. This one approves, on the authority of
a bearer token, with **no REDAXO user behind it**. That means
`HandlerInterface::canApprove()` — the privilege-escalation guard — cannot run,
because there are no user permissions to check against.

**The scope is the boundary, and there is nothing to configure.** This briefly
had a settings block — an on switch plus an allow list for types, operations, a
category fence, an offline-only rule and a batch cap. All of it was removed.
Granting `ai_platform/changes/approve` to a token is already a deliberate act
performed by someone who had to find the setting; asking the same question again
on another page added no safeguard, only a second answer that could contradict
the first — scope granted, feature reading "off", and a 403 that looks like a bug
and takes a while to trace. Everything the allow list restricted was a guess
about what the operator wanted: whoever hands out an approval scope wants
approvals — create, update and delete, published or not, wherever the content
lives.

`install.php` actively removes the six abandoned `changes_api_approve_*` keys, so
an installation that saw the earlier version does not carry dead config that
still looks meaningful.

What remains in `Change\ApiApproval` are two invariants, neither of which has a
sensible "off":

1. **Only the caller's own requests.** Approving someone else's proposal means
   deciding it — a judgement the caller was never asked for.
2. **No `force`.** A stale, deleted or dangling target is exactly the case that
   needs a person to look, and the caller cannot know what moved.
   `approveViaApi()` has no force parameter at all rather than one defaulting to
   false, so a `"force": true` in the body is simply ignored (asserted).

The actual control is not prevention but visibility: every API approval is stored
with `reviewed_via = 'api'` and flagged in the inbox as decided without review.
The badge checks status as well as channel — withdrawing also happens over the
API and also records `reviewed_via = 'api'`, and labelling a withdrawn request
"approved unattended" would state the opposite of what happened.

**Where this is administered: the api addon's token list, and nowhere else.**
The settings page briefly carried a read-only section naming the
tokens that hold the scope. It was removed too: a page whose every other block is editable
invites the reader to change what they see there, and the place to change it is a
different addon. The explanation lives in `.claude/skills/ai-platform-changes/SKILL.md`
instead — which is also what the *Anleitung* tab renders, so it reaches an editor
and an agent from one source.

`ChangeService::approve()` and `approveViaApi()` share `runApproval()`, so the
situation check, re-validation, veto extension point, write and record-keeping
cannot drift between the human and the machine path. Only the authorisation
differs, and it is settled before that method is called.

**How a decision is recorded.** `reviewed_via` (`backend` | `api` | `auto`) sits
next to `reviewed_by`, deliberately **not** folded into `status`: status answers
what happened to the request, `reviewed_via` answers through which channel
someone decided it. Folding them together would mean a new status value per
channel, and every `isOpen()` query would have to learn about each one. An API
approval leaves `reviewed_by` null rather than inventing a user id, and the
timestamp hangs on the channel instead — otherwise a token-approved request would
look never-reviewed. The inbox shows it as a second badge next to the status, and
The guide tab explains where the scope is granted.

**Nothing is deleted any more.** `purgeOlderThan()` used to `DELETE` decided
rows; it now empties `payload`, `payload_edited`, `snapshot_before` and
`context_snapshot` and keeps the row. The JSON is what takes up space; who
decided what, when, on which target, through which channel is a narrow row and
is the only thing standing between an automated write and an unexplainable
change.

Five decisions in that file that are load-bearing:

1. **`boot.php` calls `loadRoutes()` directly**, not
   `RouteCollection::registerRoutePackage()`. `getRoutes()` sets
   `$packagesLoaded = true` and never loads packages again, so a package
   registered after any addon has read the route list is dropped **silently**.
   `registerRoute()` writes into the static array immediately.
2. **The class does not extend `FriendsOfRedaxo\Api\RoutePackage`.** That base
   class holds one empty method, and inheriting would make the file unloadable
   whenever the api addon is absent. The dependency stays optional via a
   `class_exists` guard rather than `requires_addons`.
3. **`source_key` comes from the token** (`Source::apiToken()` → `api-token:<id>`),
   never from the body. It drives `changes_max_open_per_source`, the inbox filter
   and the acceptance statistics; a caller free to name its own source resets its
   quota by inventing a name. A `source_key` in the body is ignored. The new
   `Source::CHANNEL_API` keeps this distinguishable from `agent` and `php` — which
   matters, because `Source::agent()` is a self-declaration nobody verifies.
4. **Deletions get their own route**, because `BearerAuth` scopes per route, not
   per body content. `operation: "delete"` on the propose route is refused with a
   pointer to `/deletions`.
5. **Batch answers 207 on partial success**, with `ok`/`error`/`index` per
   element — a silently truncated batch answering 200 would read as "all
   submitted". There is **no size cap** and `meta.batch_limit` is `null`; the cap
   and its `changes_api_batch_limit` setting were removed, because what a batch
   does is decided by its writes and not by their number. No 413 is emitted
   anywhere; the full set of codes is 200, 201, 207, 400, 401, 404, 422, 500, 503.

Routes are not registered at all while `changes_enabled` is off — a disabled
feature has no HTTP surface, not even one returning errors — so **every path
answers 404 in that state**, which is worth knowing when diagnosing one: 404 on
every path means switched off (or no api addon), 404 on exactly one means typo.
Each handler additionally calls `refuseWhenDisabled()` and answers 503, which only
matters if the switch is flipped inside a running request; over HTTP the
registration guard gets there first.

### Offering a media file (`lib/Change/Upload/`)

The only route that accepts bytes, and the only change type whose payload points
at something outside the database.

**The enabling detail is in the core:** `rex_media_service::addMedia()` takes
`$data['file']['path']` and only falls back to `tmp_name` when that is missing —
it wants a path, not an upload. So the bytes can wait outside the media pool
until someone decides, and the approval hands over the path. Every check the
media pool runs on an interactive upload (`isAllowedExtension()`, which also
catches `x.php.jpg`, and `isAllowedMimeType()`, which compares the real mime type
against the name) runs at approval — and again at staging, so the caller learns
about a refusal now rather than from an editor hours later.

**Two steps, one handle:**

```
POST /changes/uploads   (multipart "file", or raw body + ?filename=)
  → { "upload": "up_7f3a…", "filename": "bulli.jpg", … }
POST /changes           { "type":"media", "operation":"create",
                          "target":{"category_id":3,"filename":"bulli.jpg"},
                          "fields":{"upload":"up_7f3a…","title":"…"} }
```

Not base64 in the payload: a 4 MB photo becomes ~5.5 MB of text in the `payload`
column — the column the thinning cronjob exists to keep small and the diff
renderer reads. Not a server-side URL fetch either: that is SSRF, and defending
it needs an allow list, which is the instrument this addon has been removing.

Five things that are load-bearing:

1. **The staging directory is `data/addons/ai_platform/pending/`**, which
   `redaxo/data/.htaccess` denies to the web. There is no public URL for a staged
   file and there must not be, or the directory becomes an open file host.
2. **The filename is reserved at staging, with subindexing off.**
   `rex_mediapool::filename()` would turn a collision into `bulli_2.jpg` at
   approval time, and a name the caller cannot predict is a name it cannot
   reference from a slice proposal — `SliceHandler::checkReferences()` resolves
   `media1` slots against `rex_media::get()` at *submission*. The UNIQUE index on
   `filename` is the actual guarantee; the `rex_media::get()` check only covers
   names already in the pool.
3. **`addMedia()` gets a copy, not the staged original.** It moves what it is
   given into `media/`; if it throws halfway — mime check, filesystem, an
   extension refusing in `MEDIA_ADDED` — the staged file is still there and the
   request is retryable with its evidence intact. The cronjob removes it later.
4. **`PayloadInterface::attachments()`** is how the core links a staged file to
   its request without knowing that media exist. `ChangeService::propose()` calls
   it after storing; until then the upload looks abandoned, which it is. The
   alternative — searching every payload's JSON for handle-shaped strings — is a
   table scan pretending to be a relation.
5. **The reviewer must see the file.** `rex_api_ai_change_file` streams it,
   `$published = false`, gated on `ai_changes[]`, and the path comes from the
   database row rather than the request, so `../` in a parameter has nothing to
   act on. Images are embedded and scaled by CSS — the media manager only works on
   files already in the pool. Anything not displayable inline gets a link.

**SVG is allowed, as in the media pool itself.** In the backend preview it sits
inside an `<img>` tag, where scripts in an SVG do not execute — that is the tag's
semantics, not a precaution. After approval the file is served from `/media/` on
the **frontend**, where a backend CSP does not apply; the channel adds no
capability an admin did not already have through the media pool, only the ability
for a machine to *propose* one, with a person still deciding.

**Ordering: one round per level, and a changeset does not change that.** An image
plus the slice that shows it cannot be submitted together — `checkReferences()`
resolves `media1` against `rex_media::get()` at *submission*, so the slice is
refused while the file is still only proposed. The same holds for structure: an
article-create names its `category_id`, which is checked the same way, and the id
of a category that does not exist yet cannot be named at all. So: propose,
approve, read the new id from `created` in the approval response, then the next
level. Every dependency in this feature is checked at submission — media, link
targets, target categories, modules, templates — which means none of them can be
resolved by ordering inside a batch.

What a changeset *is* for: bundling what belongs together so the reviewer decides
once instead of forty times. `approveChangeset()` applies in submission order
(`findByChangeset()` sorts by id) and that ordering is contract rather than
accident. It is not a transaction and cannot be: the REDAXO services write file
caches and fire extension points a rollback would not undo.

**Cleanup is the cronjob**, same as everything else. Two sweeps with different
fuses: files of requests decided before the cutoff, and uploads no proposal ever
referenced past their 24-hour reservation. A pending request keeps its file
however old — a proposal whose image was deleted is not a saving, it is a broken
proposal.

**Replacing an existing file is still out of scope.** A create adds something that
was not there; a replace silently changes what every article already showing that
image displays, and the reviewer would see one filename with no way to judge the
blast radius. That needs its own diff before it deserves an endpoint.

**A metainfo target requires `carrier`; it is not guessed.** It used to default
to `article`, so a media proposal that forgot the carrier came back as
`Target field "article_id" is required.` — naming a field the caller was right
not to send, and inviting it to invent an article id and write to the wrong
carrier. `buildMeta()` now demands it and `guessCarrierHint()` names the carrier
the field prefixes imply. Deliberately a hint in the error, not a fallback:
reading intent off a prefix is a guess, and a guess that silently succeeds is how
a value lands on the wrong carrier. Mixed prefixes produce no hint at all.

**YForm field names come in two forms and both are accepted.** `describe()`
qualifies them (`rex_company.name`) because one flat list covers every allowed
table and `name` exists in several; the setters take the bare column.
`ChangePayloadBuilder::unqualifyYformField()` bridges that — otherwise an agent
following the documented order (call describe, then use the names it returned)
fails on its first attempt, which is exactly what happened during the REST test.
A prefix naming a *different* table throws instead of being stripped: that means
the caller mixed up the target, and stripping it would write into the wrong
column of the right table.

Test: `.claude/tests/api-changes-test.sh` (123 asserts) seeds its own api token
via the mysql client and drives every route with curl. It deliberately does not
call the handlers in-process: that would pass even if the routes were never
registered. It asserts the 401-vs-404 distinction, that a body `source_key`
cannot override the token, and that a second proposal for one target supersedes
the first.

| Class | Responsibility |
|---|---|
| `Change\ChangeService` | The public API. `read()`, `propose()`, `proposeDelete()`, `approve()`, `reject()`, `approveMany()`, `approveChangeset()`, `editPayload()`. Owns limits, stale detection, cache-rebuild batching and the extension points. Singleton. |
| `Change\ChangeRequestStore` | The only place that speaks SQL for this feature. Rows go in as JSON and come out as hydrated target/payload objects. |
| `Change\ChangeRequest` / `Changeset` | Entities. `effectivePayload()` returns the reviewer's correction when there is one, otherwise the original — the original is never overwritten. |
| `Change\HandlerInterface` / `AbstractHandler` / `HandlerRegistry` | Per-type behaviour, collected via `AI_PLATFORM_CHANGE_HANDLERS`. The core knows nothing about slices or YForm. |
| `Change\TargetInterface` + `Change\Target\*` | Where a change points. **The named constructor fixes the operation**: `existing()` → Update, `createIn()` → Create, `forDeletion()` → Delete. "Create something that already exists" is not expressible. |
| `Change\PayloadInterface` + `Change\AbstractPatchPayload` + `Change\Payload\*` | What gets written. Typed setters validate slot ranges, field names and list formats at build time. Only explicitly set fields are part of the payload — `->value(7, '')` clears a slot, no call leaves it alone. |
| `Change\ChangePayloadBuilder` | Loose arrays → typed objects, routed through the same setters. Used by the agent tool **and** the backend form; duplicating it per entry point is how the two would drift. |
| `Change\Diff\DiffField` / `DiffRenderer` | Field-level diff, plus a hand-rolled line LCS for long text (no vendor dependency). |
| `Change\Source` | Who proposed. `source_key` is the stable identifier that drives per-source limits, list filters and later acceptance-rate reporting. |
| `Change\Support\MetaFieldRegistry` / `YformFieldRegistry` / `ModuleFieldMap` | Field metadata: the whitelists, read from the real schema. |

**Six built-in types**, registered in `boot.php`: `slice`, `article`,
`category`, `meta`, `media` (update, delete **and create** — see the upload
section), `yform` (the last only when YForm is available).
Field sets mirror exactly what the REDAXO services accept — nothing invented.

**Two guards that must not be weakened:**

1. `HandlerInterface::canApprove()` is the privilege-escalation guard. Without
   it, approving becomes a way to write past the reviewer's own REDAXO
   permissions — an agent proposes a change to a category the reviewer has no
   rights for and approval writes it anyway. Every handler runs the checks the
   corresponding backend page would.
2. **Situation detection.** `ChangeService::inspectTarget()` returns a
   `TargetInspection` with four independent findings, because they need
   different answers:
   - `gone` — target deleted. Terminal, request goes to `expired`.
   - `brokenReferences` — the payload points at something that no longer exists
     (media file, link target, module, template, related dataset). **Not
     overridable**: there is no version of "apply anyway" that writes correct
     data. Handlers implement `checkReferences()`, and `validate()` calls it via
     `assertReferencesResolve()`, so an already-dangling reference is refused at
     submission too.
   - `changed` — the target's own fields moved since the proposal
     (`base_hash`). Blocks per `changes_stale_policy` (default `block`),
     deliberately overridable, and `changedFields` names what moved.
   - `contextChanged` — the surroundings moved (`context_hash` over
     `readContext()`). Warning only. This is the **only** check a create
     operation has, since it has no target to fingerprint: without it a
     week-old "insert at position 1" would silently land in front of content
     written since.

**Things not to "fix":**

- `rex_article_service::editArticle()` is **not** a patch: it requires `name` on
  every call and writes name/template_id/priority unconditionally.
  `ArticleHandler` therefore back-fills from `snapshot_before`. Removing that
  blanks the article name on a priority-only change.
- `editArticle()` silently rewrites `template_id` to an allowed template when
  the requested one is not permitted for the category. `ArticleHandler::validate()`
  rejects it upfront instead — a diff promising template A while B gets written
  is worse than no diff.
- Priorities are **relative positions**. `rex_sql_util::organizePriorities()`
  renumbers siblings gapless, so a request for priority 6 in a one-item category
  stores 1. This is why `ChangeService` re-reads the target after a write and
  stores `values_after` in `apply_result`, and why the priority diff row carries
  a note. Not a bug to chase.
- `rex_category_service::editCategory()` *does* patch, and uses
  `catname`/`catpriority`. That asymmetry with the article service is why
  `ArticlePayload` and `CategoryPayload` are separate classes.
- `rex_media_service::updateMedia()` reads `title` and `category_id`
  unconditionally — same back-fill requirement as articles.
- There is no `editSlice()` in the core. `SliceHandler` does an UPDATE plus the
  extension point sequence `pages/content.php` fires. **The PRE EPs fire only
  when `rex::getUser()` is not null**, and that condition is load-bearing.
  `structure/history` — a stock plugin — listens on `SLICE_UPDATE` /
  `SLICE_DELETE` and calls `rex::requireUser()`. An API approval has no REDAXO
  user, so firing unconditionally made **every slice update and delete approved
  over the API fail** with `User object does not exist` and write nothing, while
  article, category, media and metainfo went through. The line carried a comment
  claiming it was safe "because a backend user exists" — true while the backend
  was the only approval path, and quietly falsified by `approveViaApi()`.
  Skipping the PRE EP costs that one history snapshot and nothing else: the
  write and every POST EP still run, and the backend path still fires
  everything. For the same reason `applyDelete()` no longer calls
  `rex_content_service::deleteSlice()` — that service fires `SLICE_DELETE`
  itself and cannot be made conditional, so its steps (delete, then
  `rex_sql_util::organizePriorities()` over article/clang/ctype/revision) are
  reproduced. `api-changes-test.sh` now approves a slice create, update and
  delete through the API path; that was the gap which let this ship, because the
  handler tests approve through the backend, where a user always exists.
- `rex_article_slice` has **value1–value20**. The api addon's slice routes only
  cover 1–19, so `value20` is unreachable over REST — a bug there, not a
  convention to copy.
- `YformFieldRegistry` filters fields against the table's **real columns**, not
  just `db_type`. An n:m `be_manager_relation` declares `db_type => text` but
  stores into a join table, so reading it raises "undefined array key". Such
  relations are intentionally out of scope for change requests.
- `changes_allowed_yform_tables` empty means **no table** — the only allow list
  left, and the only one that has to be closed by default. A YForm target can
  name any table in the database, so a fresh install must not accept a proposal
  against `rex_ycom_user`. Everything else that used to be a list is now derived:
  modules from their own output, approval from the token's scope.
- `rex_yform_manager_dataset` pools instances per (table, id) — `get()` **and**
  `getRaw()` — and `save()` writes back the whole loaded row. Reading a snapshot
  through it after anything else touched that dataset returns stale values, and
  writing from a pooled instance resets fields nobody proposed changing.
  `YformHandler::readCurrent()` therefore reads columns straight via SQL, and
  `loadDataset()` calls `clearInstance([$table, $id])` before loading for a
  write. Both are load-bearing; there is a test for each.
- `rex_url::*` already escapes the argument separator (`$escape` defaults to
  true). Passing its return through `rex_escape()` yields `&amp;amp;`, the
  browser sends `amp;type` instead of `type`, and the page never sees its
  parameter. This shipped broken once in the (since removed) entry form — the
  type links bounced back to the chooser. `change-pages-test.php` still asserts
  against it, because the mistake is easy to repeat anywhere a link is built.
- Everything in `lib/Change/` uses `rex_i18n::rawMsg()`, never `msg()`. Callers
  escape themselves (the pages) or need plain text (an agent reading an error).
  The one exception is `DiffRenderer`, which emits HTML and therefore uses
  `msg()`.
- Batches are applied request by request, **not** in one transaction. The REDAXO
  services write file caches and fire extension points that send mail or clear
  yrewrite caches; a DB rollback would not undo that and leaves a less
  consistent state than a partial success. Cache rebuilds are collected and
  flushed once per article via `ChangeService::flushCacheRebuilds()`.

**Backend pages** live under a second top-level menu entry (`pages:` in
package.yml → `ai_changes`), in the `system` block next to Structure and Media
pool. Reviewing content is editorial work; burying it under an admin-only area
would hide it from the people who do it.

Three permissions, registered in `boot.php`:

| Permission | Grants |
|---|---|
| `ai_changes[]` | page access, read-only inbox |
| `ai_changes[approve]` (OPTIONS) | approve and reject |

There used to be a third, `ai_changes[create]`, for the backend entry form. Both
are gone.

**`ai_changes[approve]` implies section access.** `rex_be_page::checkPermission()`
ANDs a page's own requirement with every parent's, so a role granted only the
option would be turned away at the top-level page and never reach the inbox it is
meant to decide in — the permission would be decorative.
`ChangeService::preparePages()` therefore clears the top-level requirement for
such a user (`setRequiredPermissions([])`). The subpages keep their own gates, so
`settings` still needs admin. This matters because the general permission and the
option sit in *different blocks* of the role form: the missing second tick is
invisible, not merely tedious. Three permission combinations are asserted in
`change-pages-test.php`.

`ChangeService::mayApprove()` wraps the check and is enforced in
`approve()`, `reject()` and `editPayload()` as well as in the pages — a blanket
gate on top of the handler's per-target `canApprove()`. Both must pass; without
the blanket one the permission would be decorative. The change settings live on
`ai_platform/settings`, which is `admin[]` as a whole, and the page still
re-checks `isAdmin()` because a page file can also be reached by direct
inclusion.

**Master switch**: `changes_enabled` sits at the top of the change block on
`ai_platform/settings`, directly above the settings it governs — it also controls
whether the menu entry exists at all.
Off means off on every route, not just the visible one:

- `ChangeService::preparePages()` (from `PAGES_PREPARED`) *removes* the page
  rather than hiding it — a hidden page is still reachable by URL.
- `pages/ai_changes.php` refuses regardless, since a bookmark or hand-typed
  `?page=ai_changes` still lands there. The check runs **before**
  `rex_view::title()`: that builds the sub navigation from the current page
  object, which is gone once the page has been removed, so titling first fatals
  instead of explaining.
- `propose()`, `approve()` and the batch variants throw via `assertEnabled()`.
- The agent tools are not registered at all.

Two traps here, both of which shipped broken once:

1. **`PAGES_PREPARED` only honours the return value.** `backend.php` does
   `$pages = registerPoint(new rex_extension_point('PAGES_PREPARED', getPages())); setPages($pages);`
   — a handler that writes through `rex_be_controller::setPages()` has its work
   overwritten one line later. `preparePages()` therefore takes and returns the
   array.
2. **A test that calls the handler directly proves nothing.** The original test
   invoked `preparePages()` and then read `getPages()`, which passes even for the
   broken write-through version. `change-pages-test.php` now reproduces the
   backend.php sequence verbatim.

**Allow lists** use explicit switches — `changes_allow_all_modules` (default on)
and `changes_allow_all_yform_tables` (default off) — rather than treating an
empty list as "all". An empty list reads identically for "not configured yet" and
"deliberately open", and the two lists cannot share one meaning without one of
them becoming unsafe. The lists are preserved while a switch is on, so turning it
off restores the previous selection.

**Guide tab** (`pages/docs.changes.php`, reachable as *KI Platform → Doku → KI Änderungen*) renders
`.claude/skills/ai-platform-changes/SKILL.md` — the same file agents load as a
skill, with the front matter stripped. One source for both audiences: two copies
would drift, and the version an agent acts on is the one that has to be right.
`docs/change-requests.md` is a fallback path in case a release packager drops
dot-directories.

**Tables**: `rex_ai_change_request`, `rex_ai_changeset`, `rex_ai_change_upload`
(see `install.php`).
Adding a column means an `ensureColumn()` line plus a reinstall. Note the two
fingerprints: `base_hash` over `snapshot_before` (the target's own fields) and
`context_hash` over `context_snapshot` (its surroundings).

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
├── settings/                     (one page: default profiles, the change-request
│                                  master switch, and — only while that is on —
│                                  stale policy, YForm allow list, handler table,
│                                  volume)
├── mcp/
│   ├── settings/                 (mcp_enabled, mcp_description, endpoint URLs, auth/scopes banner, tool list)
│   ├── oauth-clients/            (manual client CRUD + DCR-registered client list)
│   └── scope-mapping/            (per-YCom-group multi-select of all known scopes)
└── docs/
    ├── addon/                    (renders README.md via rex_markdown)
    └── changes/                  (renders the ai-platform-changes skill)

ai_changes                        (second top-level entry: the inbox, and nothing else)
```

**Change settings and guide moved here from `ai_changes`.** They were subpages of
the editorial menu entry, which meant an admin looked for the addon's
administration in two places while an editor saw tabs they could not open. What
is left under `ai_changes` is the inbox — a single page now, no subpages — which
is the only part an editor needs. `pages/ai_changes.php` therefore requires the
inbox directly instead of dispatching, but keeps the disabled-check ahead of
`rex_view::title()` for the reason described below.

**Then the change settings were merged into the settings page itself**, rather
than kept as a `settings/changes` tab beside `settings/general`. Same reasoning
one level down: a switch on one page and what it governs on another. There is no
`settings/general` or `settings/changes` any more — `change-pages-test.php`
asserts both are unreachable and that neither page file survives, alongside a
generic check that resolves every `page=` link on every rendered page against the
real page tree. That check exists because dead links render perfectly: after the
first move the settings page linked to two pages that no longer existed, and
nothing noticed until somebody clicked.

`pages/index.php` is just the dispatcher that includes the active subpage via `rex_be_controller::includeCurrentPageSubPath()`. The nesting is implemented through `package.yml`'s `subpages:` tree, which lets REDAXO resolve `?page=ai_platform/mcp/settings` → `pages/mcp.settings.php`
and `?page=ai_platform/docs/changes` → `pages/docs.changes.php`.

`pages/profiles.php` also wires up an in-page "Test connection" AJAX button when editing a profile. The handler is `rex_api_ai_test` (`$published = false`, admin-only). Image-generation profiles **cannot be fully tested** — for OpenAI the test endpoint falls back to a GET against `/v1/models/<model>` to verify the key; for the other providers it returns a "test manually via a text profile with the same key" message. Mirror this pattern if you add a new image-only provider.

### Frontend asset: `assets/profiles.js`

Drives the provider/type-aware field visibility on the profile edit form. It reads `#ai-type-select` and `#ai-provider-select` and toggles wrapper divs around each form field. There's no build step — edit the JS directly. `boot.php` only loads it when `rex::isBackend() && rex::getUser()`.

## Skills under `.claude/skills/`

Four, each answering a different question. They are the working documentation;
`CLAUDE.md` is the map, the skills are the detail.

| Skill | Für | Auch sichtbar als |
|---|---|---|
| `ai-platform` | Profile, PHP-API, Agenten, Einstieg MCP, Arbeiten am AddOn | — |
| `ai-platform-changes` | Änderungswünsche: einreichen, freigeben, zurückziehen, eigene Typen | *KI Platform → Doku → KI Änderungen* |
| `ai-platform-mcp` | MCP- und OAuth-Architektur, Remote-Fallstricke | — |
| `ai-platform-release` | Veröffentlichung nach redaxo.org | — |

`ai-platform-changes` wird vom Backend gerendert (`pages/docs.changes.php`), das
Frontmatter wird dabei entfernt. **Eine Quelle für beide Zielgruppen** — zwei
Kopien würden auseinanderlaufen, und die Fassung, nach der ein Agent handelt, ist
die, die stimmen muss.

Wenn du an `lib/` etwas änderst, das in einem Skill beschrieben ist, gehört der
Skill mit geändert. Der MCP-Skill war einmal ein Arbeitsprotokoll mit
Stage-Notizen und behauptete danach, OAuth antworte mit `501` — ein Agent, der
das liest, sucht am falschen Ende.

## Things to know before changing code

- `composer.lock` and `vendor/` are committed. Bump deps with `composer update`, run a manual test (profile create + connection test + MCP `tools/list` call), then commit the refreshed `composer.lock` + `vendor/` together — never split them across commits.
- Symfony AI 0.6 is **alpha-ish**; pinning is `^0.6`. Breaking changes between minor 0.x bumps are likely — update with care and re-run `.claude/tests/provider-registry-test.php`, which drives every provider in `ProviderRegistry` through its bridge.
- When code `exit`s mid-flow (the `FriendsOfRedaxo\AiPlatform\Mcp\Router` routes and the `rex_api_ai_test` endpoint do this), it bypasses REDAXO's normal response pipeline. Always `rex_response::cleanOutputBuffers()` first and never rely on `rex_api_result` for the response body.
- `FriendsOfRedaxo\AiPlatform\Service::getProfile()` filters by `status = 1`; inactive profiles are invisible to all callers. That's intentional — keep it that way unless adding an explicit "include inactive" parameter.
- The MCP router runs on `PACKAGES_INCLUDED` and `exit`s on match. That bypasses REDAXO's normal request lifecycle. If you add a new route, always `rex_response::cleanOutputBuffers()` before sending anything, never call `rex_response::sendContent()`, and remember the router fires on every frontend request — keep the path table minimal.
- The OAuth tables (`rex_ai_oauth_*`, `rex_ai_scope_mapping`) are created in `install.php` via `rex_sql_table::ensure*`. Adding a column → add an `ensureColumn()` line and reinstall the addon (`bin/console package:install ai_platform`, choose reinstall).
- `FriendsOfRedaxo\AiPlatform\OAuth\TokenStore` rotates refresh tokens with joint revocation: when `rotateRefreshToken()` succeeds, **both** the old refresh and its parent access token are revoked. Don't change that — it's the replay protection.
- Reproducible test harness in `.claude/tests/` (committed; DB creds derived from `data/core/config.yml`, `BASE` overridable via env):
  - `provider-registry-test.php` — 180 asserts: every provider in `ProviderRegistry` against the real REDAXO boot (so a label coming out as `[translate:…]` fails) and through a `MockHttpClient` (base-URL normalisation, bearer header, Ollama's native `/api/chat`, the two key-prefix validators), plus the assertion that every default model is offered by its catalog
  - `model-picker-test.mjs` — 19 asserts, plain `node` against a hand-rolled minimal DOM: the custom entry, credential-field visibility, the `selectpicker('refresh')` calls, the wrapper-not-select toggle, and a stored model name surviving being opened. Its provider payload is a fixture on purpose — the picker must not start failing because Symfony AI added a model
  - `oauth-storage-test.php` — 44 storage asserts, runs via REDAXO bootstrap + addon init
  - `oauth-token-endpoint-test.sh` — 20 token-endpoint asserts, seeds via mysql client, drives via curl
  - `oauth-authorize-test.sh` — 29 end-to-end asserts incl. browser-style login + consent + token exchange, uses `oauth-authorize-test-seed.php` for YCom user/group setup
  - `change-storage-test.php` — 83 asserts: payload guards, typed round-trips, stale detection, supersede (including that creates do *not* supersede each other), changesets, the YForm allow list, the PHP-module refusal, permission separation
  - `change-handlers-test.php` — 67 asserts: one real read→propose→approve→verify→restore cycle per handler against the live DB, plus the media-create cycle (stage a generated PNG, reserve its name, propose, approve, verify the bytes reached `media/`, restore) and the cronjob's abandoned-upload sweep
  - `change-situations-test.php` — 42 asserts: one section per way the world can move under a pending request — referenced media deleted, link target deleted, target deleted, text edited by hand, article gained slices, YForm dataset edited, YForm instance-pool staleness, module removed
  - `api-changes-test.sh` — 123 asserts over HTTP: all seven REST routes, scope
    enforcement (401 for a missing scope vs 404 for an unknown path), that a body
    `source_key` cannot override the token's, batch semantics incl. 207, that
    deletions and approvals each need their own scope, that self-approval writes
    and records `reviewed_via=api`, that `force` is unreachable, that withdrawing
    keeps the row, that a batch whose second element depends on its first answers
    207 with the dependent half refused at submission (the claim that a changeset
    resolves ordering was wrong in three documents and tested nowhere), and that
    nothing was applied without a recorded decision.
    Seeds and removes its own api token.
  - `change-pages-test.php` — 104 asserts: page tree, the two permissions and every combination of them, the absence of the removed entry form and of the removed settings split, the master switch removing the menu entry, the conditional detail settings from both sides (present-but-hidden while off, and a save in that state leaving the stored values alone), the staged-file preview going through the permission-checked endpoint, every remaining backend page rendered (including an XSS fixture, since payloads come from an LLM and are shown to a full-rights user), the double-escaping regression, and a sweep resolving every `page=` link on every rendered page against the real page tree
  - `backend-page-tree-check.php` — not a test: prints the addon's page tree as REDAXO resolves it, for eyeballing `package.yml` changes
  - `bootstrap.php` — shared CLI bootstrap. Three things a naive bootstrap gets wrong and this one handles: it deletes `packages.cache` (or a newly declared page stays invisible), calls `enlist()` per package (or `rex_i18n::msg()` returns `[translate:key]` for every addon string), and forces `rex_autoload::reload()` (or classes added since the last cache write are unloadable). It also injects a synthetic `Request`, because backend pages call `rex::getRequest()`, which throws in CLI.
  - Run all of them before touching anything in `lib/Mcp/`, `lib/OAuth/` or `lib/Change/` to lock down baseline.
- Profile IDs are referenced by the three `default_*_profile` config keys, but **there is no FK or cleanup** when a profile is deleted. After delete, the config still points at the gone id and the next API call throws `rex_exception('No default AI profile configured for: …')`. If you touch the delete handler in `pages/profiles.php`, consider clearing matching config keys.
