---
name: ai-platform-mcp
description: Interner Architektur-Spickzettel fuer den MCP-Server dieses Addons (Router, Authenticator, Server, Tool, Context). Use when adding routes, modifying auth, registering tools with scopes, or implementing the phase-2 OAuth 2.1 flow.
---

# AI Platform — MCP-Server-Architektur

Lokaler Spickzettel zum MCP-Stack dieses Addons. Quelle der Wahrheit bleibt der Code unter `lib/rex_ai_mcp_*.php`.

> **Hinweis:** Die „Phase 1/Phase 2"-Abschnitte weiter unten sind ein historisches Bau-Log. Aktueller, verbindlicher Stand steht hier oben. OAuth ist vollständig live, der Legacy-Endpoint `?rex-api-call=ai_mcp` ist entfernt.

## Aktueller Stand (1.0.0-beta3)

### Live-Routen
| Pfad | Methode | Handler |
|---|---|---|
| `/mcp` | POST | `FriendsOfRedaxo\AiPlatform\Mcp\Server::handle` (sonst 405) |
| `/.well-known/oauth-protected-resource` | GET | Discovery JSON |
| `/.well-known/oauth-authorization-server` | GET | Discovery JSON |
| `/oauth/authorize` | GET/POST | `FriendsOfRedaxo\AiPlatform\OAuth\AuthorizationEndpoint` (Login + Consent) |
| `/oauth/token` | POST | `FriendsOfRedaxo\AiPlatform\OAuth\TokenEndpoint` (code + refresh) |
| `/oauth/register` | POST | `FriendsOfRedaxo\AiPlatform\OAuth\DcrEndpoint` (DCR) |
| sonstiges `/oauth/*` | * | 404 `not_found` |

### Config-Keys (rex_config `ai_platform`)
| Key | Wirkung |
|---|---|
| `mcp_enabled` | 0/1 — gated `/mcp` (sonst 503) |
| `mcp_description` | geht als `instructions` in die `initialize`-Antwort |
| `mcp_require_auth` | 0/1 — bei 1 bekommt JEDER anonyme Request 401 (auch `initialize`/`tools/list`) → erzwingt OAuth-Login. **Nötig, damit geschützte Tools in Claude Desktop überhaupt auftauchen** (s.u. Henne-Ei). |
| `mcp_disabled_tools` | JSON-Liste deaktivierter Tool-Namen (Opt-out; via `FriendsOfRedaxo\AiPlatform\Mcp\Server::isToolEnabled()` in list+call) |
| `oauth_client_lifetime_days` | Tage bis ein OAuth-Client ablaeuft (Basis `createdate`). 0 = nie. Enforced in authorize+token → `invalid_client` → DCR-Re-Registrierung |

### Scopes (Stand beta3)
- **Keine built-in Scopes mehr.** `builtInScopes()` gibt `[]`. Die Konstanten `SCOPE_TOOLS_READ/CALL` sind `@deprecated`, unbenutzt.
- Tool-Sichtbarkeit/-Aufruf: `public:true` ODER (authentifiziert UND hat alle eigenen `requiredScopes` des Tools). Es gibt KEIN globales tools-Scope-Gate (würde mit public Tools kollidieren).
- Scopes kommen ausschliesslich aus AddOns via `AI_PLATFORM_OAUTH_SCOPES`, werden YCom-Gruppen zugeordnet (`rex_ai_scope_mapping`), pro User via `resolveScopesForYcomUser()` aggregiert.
- → **Gruppen-Differenzierung**: Tool A braucht `scopeA` (Gruppe X), Tool B braucht `scopeB` (Gruppe Y) → unterschiedliche Logins sehen unterschiedliche Tool-Listen am selben Server.

### OAuth-UI als ueberschreibbare Fragmente

Login-, Consent- und Error-Maske von `/oauth/authorize` liegen in Fragmenten unter `fragments/ai_platform/oauth/`:
- `page.php` — HTML-Shell + CSS (Vars: `title`, `content`)
- `login.php` — Login-Form (Vars: `error`, `hiddenFields`)
- `consent.php` — Consent-Screen (Vars: `clientName`, `userEmail`, `effective`, `omitted`, `descriptions`, `hiddenFields`)
- `error.php` — Fehlerseite (Vars: `code`, `message`)

`FriendsOfRedaxo\AiPlatform\OAuth\AuthorizationEndpoint` setzt nur noch die Daten und ruft `$fragment->parse('ai_platform/oauth/<x>.php')`. Ein Projekt ueberschreibt die Optik, indem es dieselbe Pfadstruktur in einem spaeter ladenden fragments-Verzeichnis ablegt (z.B. `project/fragments/ai_platform/oauth/login.php`) — Addon-fragments-Dirs werden automatisch registriert (`package.php` ruft `rex_fragment::addDirectory($addon/fragments)`), `project` laedt `late` und gewinnt.

**Escaping-Konvention in diesen Fragmenten:** `rex_i18n::msg()`-Ausgaben werden NICHT erneut escaped (msg() escaped bereits via `html_simplified` inkl. der `{0}`-Args) — sonst Doppel-Escaping (`"` → `&quot;` sichtbar). Reine Daten (Scope-Namen, error code/message) hingegen mit `rex_escape()`. Alle Fragment-Vars werden mit `setVar(..., false)` roh uebergeben.

## Remote/OAuth/Claude Desktop — hart erarbeitete Erkenntnisse (2026-06-09)

Beim ersten echten End-to-End-Test über ngrok + Claude Desktop aufgedeckt. Reihenfolge ≈ Auftreten:

1. **`baseUrl()` muss Host + Scheme aus dem Request ableiten** (`X-Forwarded-Host` / `X-Forwarded-Proto`), nicht nur das Scheme. Sonst zeigt die Discovery durch einen Tunnel/Proxy auf den internen Host (`redaxo.localhost`) → Anthropics DCR/authorize/token laufen ins Leere („Couldn't register with sign-in service"). `baseUrl()` ist addon-lokal (nur MCP-Discovery/Challenge), berührt NICHT die globale CMS-URL-Erzeugung — daher gefahrlos.

2. **`arg_separator.output` = `&amp;` Falle.** Die Web-php.ini hatte `arg_separator.output = "&amp;"` → `http_build_query()` baut `code=X&amp;state=Y` → der Parameter heisst `amp;state` statt `state` → claude.ai meldet `"state: Field required"`. **Immer** `http_build_query($q, '', '&')` mit explizitem `&` bei Redirect-URLs. (Im Authorize-Endpoint beide Redirect-Stellen.)

3. **rex_i18n escaped Lang-Texte mit `html_simplified`** — erlaubt nur `b, i, code, kbd, var, br`. `<strong>` erscheint literal. Platzhalter sind `{0}`, **nicht** `%s` (sonst steht `%s` wörtlich da). Literale `&` / `–` verwenden, keine Entities (`&amp;` wird doppelt-escaped).

4. **Henne-Ei bei geschützten Tools.** MCP-Clients starten OAuth nur bei einem **401**. Antwortet der Server anonym mit 200 (für public Tools), verbindet sich Claude **anonym** und sieht geschützte Tools NIE (sie sind aus `tools/list` gefiltert, und ohne Sicht kein Aufruf, der das 401 auslösen würde). Lösung: `mcp_require_auth=1`. Trade-off: anonymer Public-Zugriff ist dann für den ganzen Endpoint weg (pro-Server-Entscheidung).

5. **Claude-Desktop „Custom Connector" ist cloud-vermittelt.** OAuth/DCR laufen über **Anthropics Server (claude.ai)**, nicht vom lokalen Rechner. Eine reine `*.localhost`-Domain ist von dort unerreichbar. Für lokalen Test entweder: (a) Tunnel (ngrok) mit öffentlicher HTTPS-URL, oder (b) ein Client, der LOKAL verbindet: `mcp-remote` (stdio-Bridge) bzw. Claude Code CLI (`claude mcp add --transport http`).

6. **ngrok-Setup:** `ngrok http https://redaxo.localhost --url=https://<static>.ngrok-free.dev`.
   - **HTTPS-Upstream** nötig: Port 80 macht force-https 301; mit `https://redaxo.localhost` passen SNI + Cert und Apache trifft den richtigen vhost (fremder Host → yrewrite 404).
   - ngrok setzt `X-Forwarded-Host` → mit der baseUrl-Logik (Pkt. 1) liefert die Discovery die ngrok-Domain **zero-config**.
   - **mkcert-Trust:** Node-basierte Clients (mcp-remote, Claude Code) brauchen `NODE_EXTRA_CA_CERTS=$(mkcert -CAROOT)/rootCA.pem`. Chromium/Claude-Desktop nutzen den macOS-System-Keychain (sofern `mkcert -install` lief).
   - **ngrok-Free Interstitial:** kann bei Browser-Navigationen eine Warnseite zeigen; JSON-Fetches (Accept: application/json, non-browser UA) sind i.d.R. nicht betroffen.

7. **DCR muss `client_secret_post` unterstützen.** Claude registriert sich als **confidential** client (`token_endpoint_auth_method: client_secret_post`), nicht public. Die DCR muss `none` UND `client_secret_post` akzeptieren und für confidential ein `client_secret` (+ `client_secret_expires_at`) zurückgeben. Token-Endpoint liest `client_secret` aus dem Body und verifiziert (PKCE bleibt zusätzlich Pflicht). Die Discovery muss `client_secret_post` in `token_endpoint_auth_methods_supported` listen.

8. **MCP-Endpoint-Pfad muss exakt `/mcp` sein.** Claude postet die JSON-RPC an die Connector-URL. Endet diese nur auf der Domain, gehen die Calls an `/` → REDAXO-Startseite (HTML, 200) → „Authorization failed". → Connector-URL immer mit `/mcp` eintragen.

9. **Token ist eine Momentaufnahme.** Scopes werden beim `/oauth/authorize` aus den YCom-Gruppen in den Token geschrieben. Gruppen-/Mapping-Änderungen wirken erst nach **Neu-Autorisierung** (Refresh erweitert Scopes nicht). User ohne Gruppe → Consent zeigt „keine Berechtigungen", Token ohne Scopes.

10. **Server `instructions` + Tool `description`** werden korrekt gesendet (verifiziert), aber Claude Desktop zeigt sie nicht prominent: `instructions` geht als Modell-Kontext, die Tool-Liste zeigt oft nur Namen. Kein Server-Bug.

11. **Bestes Debug-Tool: der ngrok-Inspektor** `http://127.0.0.1:4040/api/requests/http?limit=N` (lokal erreichbar, da ngrok auf derselben Maschine läuft). Zeigt die echten Requests von Anthropic (UA `python-httpx`) inkl. Headern und Bodies — so findet man DCR-Payload (`client_secret_post`!), fehlendes `state`, falschen Pfad (`/` statt `/mcp`) etc. Roh-Request/Response sind base64 in `request.raw`/`response.raw`.

## Klassen-Topologie

```
PACKAGES_INCLUDED Hook (boot.php)
   ↓
FriendsOfRedaxo\AiPlatform\Mcp\Router::dispatch()        ← Pfad-Match auf REQUEST_URI, sonst return
   ↓ (on match)
FriendsOfRedaxo\AiPlatform\Mcp\Server                    ← JSON-RPC Handler, init/list/call/ping
   ↓
FriendsOfRedaxo\AiPlatform\Mcp\Authenticator             ← liefert Context (Phase 1: immer anonymous)
   ↓
FriendsOfRedaxo\AiPlatform\Mcp\Context                   ← Value Object: YCom-User, Scopes, AuthMode
   ↓
FriendsOfRedaxo\AiPlatform\Mcp\Tool::execute($args, $ctx)
```

## Route-Tabelle (HISTORISCH — aktueller Stand siehe oben „Live-Routen")

| Pfad | Methode | Handler | Status |
|---|---|---|---|
| `/mcp` | POST | `FriendsOfRedaxo\AiPlatform\Mcp\Server::handle` | aktiv |
| `/mcp` | GET, andere | — | 405 Method Not Allowed |
| `/.well-known/oauth-protected-resource` | GET | inline JSON | aktiv (Discovery) |
| `/.well-known/oauth-authorization-server` | GET | inline JSON | aktiv (Discovery-Skeleton) |
| `/oauth/authorize` | * | — | 501 (Phase 2) |
| `/oauth/token` | * | — | 501 (Phase 2) |
| `/oauth/register` | * | — | 501 (Phase 2) |
| `index.php?rex-api-call=ai_mcp` | POST | `rex_api_ai_mcp` → `FriendsOfRedaxo\AiPlatform\Mcp\Server` | deprecated, bleibt fuer BC |

Routing laeuft komplett ohne `.htaccess`-Eingriff — der Router hookt sich in `PACKAGES_INCLUDED` ein und `exit`et bei Match. Trade-off: Hook feuert auf JEDEM Frontend-Request, also Route-Tabelle klein halten.

## Auth-Modell

| Modus | Wer | Wann |
|---|---|---|
| `anonymous` | nicht eingeloggt | Default. `public: true` Tools funktionieren. Geschuetzte Tools → 401 mit `WWW-Authenticate` |
| `oauth` | YCom-User mit OAuth-Token | Phase 2. Scopes kommen aus YCom-Gruppe → Scope-Mapping |

**Wichtig:** Der frueher in `rex_config('ai_platform', 'mcp_token')` hinterlegte feste Bearer-Token ist seit 1.0.0-beta2 **weg**. `install.php` ruft `rex_config::remove(...)` aktiv auf. Wer den Token noch im Code annimmt, faellt um.

Die 401-Antwort sendet:
```
WWW-Authenticate: Bearer realm="MCP", resource="<server>/.well-known/oauth-protected-resource"
```
Das ist MCP-Spec-konform — Clients wie `mcp-remote` lesen das `resource` raus und folgen der Discovery.

## Tool-Registration

```php
$tools['my_tool'] = new FriendsOfRedaxo\AiPlatform\Mcp\Tool(
    name: 'my_tool',
    description: '...',
    inputSchema: [...],
    handler: fn (array $args, FriendsOfRedaxo\AiPlatform\Mcp\Context $ctx): string => '...',
    public: false,                         // default false
    requiredScopes: ['mcp:tools:call'],    // greift erst mit Phase 2
);
```

Sichtbarkeit:
- `public: true` → erscheint in `tools/list` fuer alle Caller, callable ohne Auth
- `public: false` ohne Authentifizierung → **nicht** in `tools/list` (anonyme Caller sehen das Tool gar nicht)
- `public: false` + authentifiziert ohne passende Scopes → sichtbar in `tools/list`, `tools/call` antwortet mit error-content `Missing required scope`

`$context->getYcomUser()` ist `null` ausserhalb von YCom-Auth. Tools muessen damit umgehen koennen.

## Phase 2 — Stand

### Stage 2a (erledigt 2026-06-01) — Storage Layer + HTTPS-Fix

**Tabellen** (via `install.php`, alle mit `ensureGlobalColumns()` und `id` als PK):

| Tabelle | Zweck |
|---|---|
| `rex_ai_oauth_client` | Client-Registry. `client_secret_hash` ist null bei `type=public`, `password_hash()` bei `type=confidential`. `created_by_dcr` markiert per-DCR angelegte Clients. UNIQUE auf `client_id`. |
| `rex_ai_oauth_authorization_code` | Einmal-Codes. `code_hash` ist SHA-256 hex. `code_challenge` + `code_challenge_method` (S256 only). `used_at` markiert Verbrauch. UNIQUE auf `code_hash`, Index auf `expires_at`. |
| `rex_ai_oauth_token` | Access + Refresh Tokens in einer Tabelle, unterschieden durch `type`. `parent_token_id` linkt Refresh→Access. `revoked_at` weicht-loescht. UNIQUE auf `token_hash`, Indizes auf `(type, expires_at)` und `ycom_user_id`. |
| `rex_ai_scope_mapping` | YCom-Gruppe (UNIQUE) → Scope-Liste (JSON). |

**Klassen:**

- `FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry` — Built-in Scopes (`mcp:tools:read`, `mcp:tools:call`), Extension Point `AI_PLATFORM_OAUTH_SCOPES` fuer Drittaddons, Gruppen→Scope-Mapping CRUD, `resolveScopesForYcomUser()` aggregiert via `rex_ycom_user::getGroups()`.
- `FriendsOfRedaxo\AiPlatform\OAuth\ClientStore` — `create()`, `findByClientId()`, `findAll()`, `deleteById()`, `markUsed()`, `verifySecret()` (password_verify), `redirectUriMatches()` (exact-match Liste).
- `FriendsOfRedaxo\AiPlatform\OAuth\TokenStore` — `issueAuthorizationCode()`, `consumeAuthorizationCode()` (single-use, expiry-aware), `issueTokenPair()`, `findAccessToken()`, `rotateRefreshToken()` (revoked alten Pair), `revokeAllForClient()`. Token-Lifetimes als Konstanten: ACCESS=3600s, REFRESH=30d, CODE=600s.

**Sicherheitsprinzipien:**
- Alle Token + Codes als SHA-256-hex in der DB, Plaintext nur einmal bei Issue zurueckgegeben.
- Client-Secrets via `password_hash(PASSWORD_DEFAULT)`.
- Refresh-Token-Rotation: nach Einlosen wird das alte Pair revoked, das vermeidet Token-Replay.

**HTTPS-Fix:** `FriendsOfRedaxo\AiPlatform\Mcp\Router::baseUrl()` ueberschreibt das Scheme aus `rex::getServer()` mit dem tatsaechlichen Request-Scheme (`$_SERVER['HTTPS']`, `X-Forwarded-Proto`, Port 443). Discovery-URLs zeigen jetzt durchgaengig korrekt `https://...` an, statt der REDAXO-Config-Default.

**Test-Skript:** `.claude/tests/oauth-storage-test.php` — 44 Asserts. Setzt $REX globals, bootet REDAXO + Addons via `rex_addon::initialize()` + `getPackageOrder()` Loop, raeumt DB vor und nach Test. Reproduzierbar via `php .claude/tests/oauth-storage-test.php` aus dem Addon-Root.

### Stage 2b (erledigt 2026-06-01) — Token-Endpoint + Bearer-Validation

**`lib/OAuth/TokenEndpoint.php`** — Handler fuer `POST /oauth/token`. Akzeptiert sowohl `application/x-www-form-urlencoded` als auch `application/json`. Implementiert:

- `grant_type=authorization_code` — required params: `code`, `redirect_uri`, `client_id`, `code_verifier`; bei confidential clients zusaetzlich `client_secret`. Verifiziert PKCE S256 (`base64url(sha256(verifier)) === code_challenge`) und matcht redirect_uri + client_id gegen das gespeicherte Code-Row.
- `grant_type=refresh_token` — rotiert das alte Pair (revoked beide), gibt neues access+refresh aus.
- OAuth-Error-Konvention: 400 fuer invalid_request/invalid_grant/unsupported_grant_type, 401 fuer invalid_client. Body als `{"error": "...", "error_description": "..."}`.
- Cache-Headers: `Cache-Control: no-store`, `Pragma: no-cache` damit Proxies keine Tokens behalten.

**`FriendsOfRedaxo\AiPlatform\Mcp\Authenticator`** — `authenticate()` schaut bei vorhandenem Bearer-Header in `FriendsOfRedaxo\AiPlatform\OAuth\TokenStore::findAccessToken()` nach und gibt einen authentifizierten Context mit `ycomUserId`, `scopes`, `authMode='oauth'`, `clientId` zurueck. Ist der Token unbekannt/abgelaufen/revoked, wirft die Methode `FriendsOfRedaxo\AiPlatform\Mcp\InvalidTokenException` (NEU). Das `buildChallengeHeader()` nimmt jetzt optional `$error` + `$errorDescription` und baut nach RFC 6750 §3.1 `Bearer realm="MCP", resource="…", error="invalid_token", error_description="…"`.

**`FriendsOfRedaxo\AiPlatform\Mcp\Server`** — Authenticate liegt jetzt im selben `try`-Block wie das Dispatch. Faengt zusaetzlich `FriendsOfRedaxo\AiPlatform\Mcp\InvalidTokenException` und ruft `sendAuthChallenge(..., 'invalid_token')` auf — Clients sehen den exakten Grund (abgelaufen vs. nie ausgestellt) und koennen entsprechend reagieren.

**`FriendsOfRedaxo\AiPlatform\Mcp\Router`** — `POST /oauth/token` geht jetzt direkt an `FriendsOfRedaxo\AiPlatform\OAuth\TokenEndpoint::dispatch()` statt 501. `GET /oauth/token` antwortet weiter mit 405 (Allow: POST). `/oauth/authorize` und `/oauth/register` bleiben 501 bis Stage 2c.

**Sicherheitsdetails:**
- Refresh-Rotation: das alte Refresh-Token UND das verlinkte Access-Token werden gemeinsam revoked (parent_token_id-Loesung im Store) — schliesst die Replay-Tuer zu.
- PKCE S256-only: der Verifier-Range (43-128 chars) wird explizit gegen die Spec geprueft. Plain method ist nicht erlaubt.
- Bei `invalid_client` (401) wird *kein* WWW-Authenticate gesendet, weil das nicht der MCP-Resource-Server-Pfad ist; OAuth-spec-konform liegt der Fehler im Token-Endpoint-Body.

**Test-Skript:** `.claude/tests/oauth-token-endpoint-test.sh` — 20 Asserts via curl + mysql-seeded Codes. Reproduzierbar via `.claude/tests/oauth-token-endpoint-test.sh` (haendelt setup + teardown selbst). Storage-Tests laufen separat: `php .claude/tests/oauth-storage-test.php`.

### Stage 2c (erledigt 2026-06-01) — Authorize + DCR + Backend-UI

**`lib/OAuth/AuthorizationEndpoint.php`** — Eine Klasse, drei Zustände auf demselben Pfad:

1. GET ohne YCom-Session → standalone HTML-Login-Form (Login + Passwort), alle OAuth-Params als hidden inputs erhalten
2. POST `_action=login` → `rex_ycom_auth::login(['loginName' => ..., 'loginPassword' => ..., 'filter' => [], 'ignorePassword' => false])`. Bei Erfolg fallthrough zum Consent-Screen, bei Fehler re-render der Login-Form mit Fehlermeldung
3. GET/POST mit Session → Consent-Screen mit `effective = intersect(requested, user_scopes)`. Zeigt explizit weggefilterte (`requested \ effective`) Scopes als Warnung
4. POST `_action=consent` mit `decision=allow` → `issueAuthorizationCode()` + 302-Redirect zu `redirect_uri?code=...&state=...`. Bei `deny` → 302 mit `?error=access_denied&state=...`

**Wichtiger Bug-Fix:** Mein Router-Hook und YComs `init()` registrieren beide auf `PACKAGES_INCLUDED`. Die Reihenfolge ist nicht stabil — bei meiner ersten Implementierung lief mein Router ZUERST, sodass `rex_ycom_auth::getUser()` immer `null` zurueckgab (die statische `$me` war noch nicht aus der Session rehydratisiert). Workaround: Der Authorize-Dispatch ruft `rex_ycom_auth::init()` explizit vor dem ersten `getUser()`-Aufruf. Ohne diesen Schritt funktioniert kein einziger Folge-Request nach erfolgreichem Login.

**Security-Konventionen:**
- Unbekannter `client_id` oder mismatched `redirect_uri` → 400 Error-Page (keine Weiterleitung an unvertrauenswuerdige URLs).
- Alle anderen Fehler (response_type, code_challenge, etc.) → 302 zum registrierten `redirect_uri` mit `?error=...` damit der Client den Fehler verarbeiten kann.
- PKCE S256 ist Pflicht — keine andere Method, kein Weglassen.
- Login geht ueber die normale YCom-Login-Methode mit deren Auth-Rules (Brute-Force-Schutz inklusive).

**`lib/OAuth/DcrEndpoint.php`** — `POST /oauth/register` (RFC 7591) mit Auto-Approve. JSON-Body mit `redirect_uris` (pflicht, absolute URLs), `client_name` (default "Dynamic client"), `token_endpoint_auth_method` (muss "none" sein). Erstellt einen public client mit `created_by_dcr=1`. Antwortet mit 201 + Standard-RFC-7591-Payload.

**Backend-Pages:**
- `pages/oauth-clients.php` — Liste aller Clients (Name, ID, Typ, Redirect-URIs, DCR-Badge, last_used), Form zum manuellen Anlegen (public oder confidential — Secret wird nur einmal beim Create angezeigt), Loeschen mit Token-Revoke.
- `pages/scope-mapping.php` — Iteriert ueber alle `rex_ycom_group`-Eintraege, pro Gruppe Multi-Select aller bekannten Scopes (built-in + via `AI_PLATFORM_OAUTH_SCOPES` registriert). Speichert via `FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry::setScopesForGroup()`.

**Test-Skripte:**
- `.claude/tests/oauth-authorize-test-seed.php` — PHP-Helper, der per `seed` ein YCom-Group + Scope-Mapping + Test-User mit `rex_login::passwordHash()`-Passwort anlegt und JSON mit Credentials zurueckgibt; per `cleanup <user_id> <group_id>` raeumt er alles wieder ab.
- `.claude/tests/oauth-authorize-test.sh` — 29 Asserts: DCR (Happy + Fehlerpfade + 405), Authorize-Negativpfade (unknown client_id, redirect mismatch, falsches response_type, fehlendes code_challenge), Login-Screen-Rendering (5 Asserts), wrong-password-Path, Consent-Rendering (4 Asserts), Deny-Redirect, Allow-Redirect inkl. Code-Extraktion, Token-Exchange, abschliessender `/mcp tools/list` mit Bearer.

**Wichtig fuer set-e in Bash-Tests:** `set -euo pipefail` bricht bei `grep | head` ab wenn grep nichts findet (Pipe-Exit ist 1). Im Authorize-Test daher nur `set -uo pipefail` ohne `-e` — die Assert-Funktion zaehlt selber.

### Backend-Navigation (Stand 1.0.0-beta3)

```
ai_platform/                      pages/index.php   (dispatcher → includeCurrentPageSubPath)
├── profiles                      pages/profiles.php
├── settings                      pages/settings.php
├── mcp                           (parent page, REDAXO redirects to first child)
│   ├── settings                  pages/mcp.settings.php
│   ├── oauth-clients             pages/mcp.oauth-clients.php
│   └── scope-mapping             pages/mcp.scope-mapping.php
└── docs                          pages/docs.php
```

**REDAXO-Konvention fuer nested subpages:** Dot-Notation in Dateinamen, NICHT Verzeichnisse. `?page=ai_platform/mcp/settings` resolved automatisch zu `pages/mcp.settings.php` ueber `rex_be_controller::pageSetSubPaths()`. Beim Bauen weiterer Subpages immer mit Punkt im Dateinamen arbeiten, sonst findet REDAXO die Datei nicht.

**Test-Skript fuer Page-Tree:** `.claude/tests/backend-page-tree-check.php` — bootet REDAXO + Addons, ruft `rex_be_controller::appendPackagePages()` (sonst leer in CLI-Mode) und verifiziert dass jeder Page-Schluessel zu einer existierenden Datei aufloest.

## Phase 1 — verifiziert am 2026-06-01

Manueller curl-Smoke gegen die lokale Instanz, alle Cases gruen:

| Case | Erwartung | Ergebnis |
|---|---|---|
| `GET /.well-known/oauth-protected-resource` | 200 JSON mit `resource` + `authorization_servers` | ok |
| `GET /.well-known/oauth-authorization-server` | 200 JSON mit `issuer` + Endpunkten + `S256` | ok |
| `POST /mcp` `initialize` | 200 + `protocolVersion 2025-03-26` + serverInfo + `instructions` aus mcp_description | ok |
| `POST /mcp` `tools/list` (anonymous) | nur Tools mit `public: true` | nur `redaxo_status` |
| `POST /mcp` `tools/call redaxo_status` | 200 mit REDAXO-Status-JSON im content[0].text | ok |
| `POST /mcp` `tools/call` unknown tool | 200 mit `isError: true` content | ok |
| `POST /mcp` `ping` | 200 mit `{}` | ok |
| `POST /mcp` unknown method | 200 mit JSON-RPC error -32601 | ok |
| `GET /mcp` | 405 + `Allow: POST` | ok |
| `GET /oauth/{authorize,token,register}` | 501 + `not_implemented` JSON | ok |
| `POST /index.php?rex-api-call=ai_mcp` (Legacy) | 200, identisch zum `/mcp`-Pfad | ok |
| `tools/list` mit registriertem protected Tool | protected NICHT in der Liste fuer anonymous | ok |
| `tools/call` auf protected Tool (anonymous) | 401 + `WWW-Authenticate: Bearer realm="MCP", resource="…"` | ok |

Der 401-Test lief mit einem temporaer in `boot.php` eingehaengten `_phase1_protected` Tool (`public: false`, `requiredScopes: ['mcp:tools:call']`), wurde nach dem Test wieder entfernt.

## Follow-ups fuer Phase 2

- **`rex::getServer()` Scheme-Issue:** Die Discovery-URLs entstehen aus `rex::getServer()`. Wenn die REDAXO-Instanz mit `SERVER: http://...` konfiguriert ist (aber per HTTPS gehosted), zeigen `authorization_servers` und `resource` HTTP-URLs, was den OAuth-Flow im Browser bricht (mixed content / Redirect-Mismatch). Beim Phase-2-Bau muss der Router entweder
  (a) `$_SERVER['HTTPS']` / `X-Forwarded-Proto` beruecksichtigen,
  (b) das Scheme aus `rex::getServer()` durch `https` ueberschreiben wenn der Request via TLS reinkommt, oder
  (c) eine eigene `mcp_base_url` Config-Option anbieten, die der Admin pinnt.
  Option (b) ist am robustesten — kein Pflege-Aufwand fuer den User.

## Stolperfallen

- **`PACKAGES_INCLUDED` feuert auch fuer Backend-Requests via `redaxo/index.php`** wenn der frueh genug ist — daher der `rex::isBackend()` Guard in `dispatch()`. Backend-URLs duerfen NICHT vom MCP-Router geschluckt werden.
- **`exit()` umgeht REDAXO-Cleanup** (z.B. `rex_response::sendCacheControl()`). Output Buffers vorher `cleanOutputBuffers()`. Kein `rex_response::sendContent()` — direkt `echo` + `exit`.
- **`mcp_enabled` Default ist `0`** — frischer Install antwortet auf `/mcp` mit 503. User muss im Backend aktivieren.
- **Notifications (kein `id` im JSON-RPC)** beantwortet der Server mit 202 ohne Body. Wer aus Versehen ohne `id` callt, sieht "leeren" Response und denkt was sei kaputt.
- **`rex::getServer()` hat Trailing-Slash** — bei URL-Konkatenation `rtrim()` oder Slash-Doppelung pruefen.

## Quick-Test (curl)

```bash
# Initialize (public, kein Auth noetig)
curl -s -X POST https://redaxo.localhost/mcp -k \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'

# tools/list als anonymous — zeigt nur public Tools
curl -s -X POST https://redaxo.localhost/mcp -k \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'

# redaxo_status aufrufen
curl -s -X POST https://redaxo.localhost/mcp -k \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"redaxo_status","arguments":{}}}'

# Discovery
curl -s -k https://redaxo.localhost/.well-known/oauth-protected-resource
curl -s -k https://redaxo.localhost/.well-known/oauth-authorization-server
```
