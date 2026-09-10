---
name: ai-platform-mcp
description: MCP- und OAuth-Architektur des AddOns ai_platform — Router, Authenticator, Server, Tool, Context, und die vollstaendig implementierte OAuth-2.1-Schicht mit PKCE, DCR und Refresh Tokens. Use when adding MCP routes, changing authentication, registering tools with scopes, or debugging a remote client (Claude Desktop, ngrok, reverse proxy).
---

# KI Platform — MCP-Server-Architektur

Verwandte Skills: **`ai-platform`** für Profile, Service-API und Agenten,
**`ai-platform-changes`** für Änderungswünsche (die ausdrücklich *nicht* über MCP
laufen), **`ai-platform-release`** für den Veröffentlichungsweg.

Lokaler Spickzettel zum MCP-Stack dieses Addons. Quelle der Wahrheit bleibt der Code unter `lib/Mcp/` und `lib/OAuth/` (PSR-4, Namespace `FriendsOfRedaxo\AiPlatform\…`).

> **Hinweis:** OAuth ist vollständig live — Authorization Code + PKCE, Refresh mit Rotation, DCR. Der Legacy-Endpoint `?rex-api-call=ai_mcp` ist entfernt, `/mcp` ist der einzige MCP-Einstieg. Das frühere „Phase 1/Phase 2"-Bau-Log steht nicht mehr in diesem Skill; wer es braucht, findet es in der Git-Historie.

## Aktueller Stand

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

### Scopes
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

## Remote/OAuth/Claude Desktop — hart erarbeitete Erkenntnisse

Beim ersten echten End-to-End-Test über ngrok + Claude Desktop aufgedeckt. Jeder
Punkt hat einmal einen halben Tag gekostet; die Reihenfolge entspricht dem
Auftreten.

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
FriendsOfRedaxo\AiPlatform\Mcp\Authenticator             ← liefert Context (anonym oder OAuth)
   ↓
FriendsOfRedaxo\AiPlatform\Mcp\Context                   ← Value Object: YCom-User, Scopes, AuthMode
   ↓
FriendsOfRedaxo\AiPlatform\Mcp\Tool::execute($args, $ctx)
```

## Tool-Registration

```php
$tools['my_tool'] = new FriendsOfRedaxo\AiPlatform\Mcp\Tool(
    name: 'my_tool',
    description: '...',
    inputSchema: [...],
    handler: fn (array $args, FriendsOfRedaxo\AiPlatform\Mcp\Context $ctx): string => '...',
    public: false,                         // default false
    requiredScopes: ['produkte/lesen'],    // eigener Scope des AddOns, via AI_PLATFORM_OAUTH_SCOPES
);
```

Sichtbarkeit:
- `public: true` → erscheint in `tools/list` fuer alle Caller, callable ohne Auth
- `public: false` ohne Authentifizierung → **nicht** in `tools/list` (anonyme Caller sehen das Tool gar nicht)
- `public: false` + authentifiziert ohne passende Scopes → sichtbar in `tools/list`, `tools/call` antwortet mit error-content `Missing required scope`

`$context->getYcomUser()` ist `null` ausserhalb von YCom-Auth. Tools muessen damit umgehen koennen.

## OAuth 2.1: die Teile und ihre Aufgaben

Vollständig implementiert — Authorization Code + PKCE (S256 only), Refresh mit
Rotation, Dynamic Client Registration. Was früher hier als „Phase 2" und
„Stage 2a/b/c" mit Datumsangaben stand, war ein Arbeitsprotokoll; ein Skill soll
sagen, wie es *ist*.

### Tabellen

| Tabelle | Zweck |
|---|---|
| `rex_ai_oauth_client` | Client-Registry. `client_secret_hash` null bei public (PKCE only), `password_hash()` bei confidential. `created_by_dcr` markiert DCR-Clients. UNIQUE auf `client_id`. |
| `rex_ai_oauth_authorization_code` | Einmal-Codes. `code_hash` SHA-256 hex, `code_challenge` + `code_challenge_method` (S256 only), `used_at` als Verbrauchsmarke. |
| `rex_ai_oauth_token` | Access + Refresh in einer Tabelle, unterschieden durch `type`. `parent_token_id` verknüpft Refresh→Access für gemeinsames Widerrufen. |
| `rex_ai_scope_mapping` | YCom-Gruppe (UNIQUE) → Scope-Liste (JSON). |

Alles Sensible (Codes, Access, Refresh) liegt als SHA-256-hex in der DB;
Klartext wird genau einmal bei der Ausgabe zurückgegeben. Client-Secrets über
`password_hash(PASSWORD_DEFAULT)`.

### Klassen

| Klasse | Aufgabe |
|---|---|
| `OAuth\AuthorizationEndpoint` | `/oauth/authorize` — eine Klasse, drei Zustände: Login-Maske, Consent, Weiterleitung mit Code (oder `access_denied`). |
| `OAuth\TokenEndpoint` | `/oauth/token` — `authorization_code` prüft redirect_uri, client_id und PKCE; `refresh_token` rotiert. |
| `OAuth\DcrEndpoint` | `/oauth/register` — RFC 7591. Nimmt `none` **und** `client_secret_post`. |
| `OAuth\ClientStore` | CRUD, `verifySecret()` (password_verify), `redirectUriMatches()` (exakte Liste). |
| `OAuth\TokenStore` | Codes ausgeben/einlösen, Paare ausgeben, Refresh rotieren. Lifetimes als Konstanten: `ACCESS_TOKEN_TTL=3600`, `REFRESH_TOKEN_TTL=30d`, `CODE_TTL=600`. |
| `OAuth\ScopeRegistry` | Gruppen↔Scope-CRUD, `resolveScopesForYcomUser()`. **Keine eingebauten Scopes** — `builtInScopes()` gibt `[]` zurück, die `SCOPE_TOOLS_*`-Konstanten sind deprecated und unbenutzt. |
| `Mcp\Authenticator` | Kein Header → anonym. Gültiger Bearer → Context mit `ycomUserId` + `scopes`. Ungültig → `Mcp\InvalidTokenException`. |

### Drei Dinge, die nicht verändert werden dürfen

**Refresh-Rotation widerruft beide.** Beim Einlösen werden das alte Refresh-Token
**und** das über `parent_token_id` verknüpfte Access-Token widerrufen. Das ist der
Replay-Schutz.

**PKCE ist S256-only.** Der Verifier-Bereich (43–128 Zeichen) wird gegen die Spec
geprüft, `plain` ist nicht erlaubt.

**Bei `invalid_client` (401) kein `WWW-Authenticate`.** Das ist der
Token-Endpunkt, nicht der MCP-Resource-Server-Pfad; der Fehler gehört
spec-konform in den Body.

**YCom-Init-Reihenfolge.** `AuthorizationEndpoint::dispatch()` ruft
`rex_ycom_auth::init()` explizit auf, bevor `getUser()` das erste Mal läuft.
YComs eigener Init hängt am selben `PACKAGES_INCLUDED`, und die Reihenfolge ist
nicht stabil — ohne den Aufruf gibt `getUser()` nach jedem erfolgreichen Login
auf dem Folge-Request null zurück. Das war der Bug, an dem der ganze Flow hing.

### Ein Token ist eine Momentaufnahme

Scopes werden beim `/oauth/authorize` aus den YCom-Gruppen in den Token
geschrieben. Änderungen an Gruppen oder am Mapping wirken erst nach einer
**neuen Autorisierung** — ein Refresh erweitert Scopes nicht. Ein User ohne
Gruppe bekommt einen Token ohne Scopes, und der Consent-Screen sagt „keine
Berechtigungen".

## Stolperfallen

- **`PACKAGES_INCLUDED` feuert auch für Backend-Requests** — daher der
  `rex::isBackend()`-Guard in `dispatch()`. Backend-URLs dürfen nicht vom
  MCP-Router geschluckt werden.
- **`exit()` umgeht REDAXOs Cleanup.** Vorher `cleanOutputBuffers()`, kein
  `rex_response::sendContent()`, direkt `echo` + `exit`.
- **`mcp_enabled` ist standardmäßig `0`** — eine frische Installation antwortet
  auf `/mcp` mit 503, bis jemand aktiviert.
- **Notifications (kein `id` im JSON-RPC)** werden mit 202 ohne Body
  beantwortet. Wer versehentlich ohne `id` aufruft, sieht eine „leere" Antwort
  und hält sie für einen Fehler.
- **`rex::getServer()` hat einen Trailing-Slash** — bei Konkatenation `rtrim()`.
- **`arg_separator.output`** kann in der php.ini auf `&amp;` stehen. Dann baut
  `http_build_query()` `code=X&amp;state=Y`, der Parameter heißt `amp;state`, und
  claude.ai meldet „state: Field required". Immer
  `http_build_query($q, '', '&')` mit explizitem Trenner.

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
