# AI Platform - REDAXO AddOn

Die **AI Platform** ist das zentrale AddOn fuer die Integration von KI-Diensten in REDAXO. Es bietet eine einheitliche Schnittstelle zu verschiedenen LLM-Providern und stellt einen MCP-Server bereit, ueber den andere AddOns Tools fuer KI-Agenten anbieten koennen.

## Features

- Verwaltung mehrerer AI-Provider und API-Keys ueber Profile
- Pro Profil ein Typ (Text/Code, Embeddings, Bildgenerierung, Bildverstaendnis) mit typspezifischen Einstellungen
- Automatische Modell-Vorauswahl je nach Provider und Typ
- API-Verbindungstest direkt im Backend
- MCP-Server (Model Context Protocol) als HTTP-Endpoint auf `/mcp` mit OAuth-2.1-Discovery
- OAuth-2.1-Autorisierung mit PKCE + Refresh Tokens + Dynamic Client Registration, YCom als Identity-Provider
- Scope-System mit Mapping `YCom-Gruppe → Scopes` ueber das Backend
- Eingebautes `redaxo_status` MCP-Tool (Systeminfos der REDAXO-Instanz, public)
- Extension Points fuer andere AddOns (MCP-Tools, Agent-Tools, eigene Scopes)
- Basiert auf [Symfony AI](https://symfony.com/ai) (v0.6)

## Unterstuetzte Provider

| Provider | Text | Embeddings | Bildgenerierung | Bildverstaendnis |
|---|---|---|---|---|
| **OpenAI** | `gpt-4o`, `gpt-4o-mini`, `o1`, `o3-mini` | `text-embedding-3-small`, `text-embedding-3-large`, `text-embedding-ada-002` | `dall-e-3`, `gpt-image-1` | `gpt-4o`, `gpt-4o-mini` |
| **Anthropic** | `claude-sonnet-4-20250514`, `claude-opus-4-20250514`, `claude-3-7-sonnet-latest` | - | - | `claude-sonnet-4-20250514`, `claude-opus-4-20250514` |
| **Google** | `gemini-2.5-flash`, `gemini-2.5-pro` | `text-embedding-004` | `gemini-2.0-flash-exp` | `gemini-2.5-flash`, `gemini-2.5-pro` |
| **Ollama** | `llama3.2`, `mistral`, `deepseek-r1` u.a. | `nomic-embed-text`, `mxbai-embed-large` | - | `llava`, `llama3.2-vision` |

Bei Ollama wird kein API-Key benoetigt, nur die Basis-URL (Standard: `http://localhost:11434`).

## Installation

1. AddOn im REDAXO-Installer herunterladen oder in `redaxo/src/addons/ai_platform/` ablegen
2. AddOn in REDAXO installieren und aktivieren

Die Symfony-AI-Abhaengigkeiten sind im Release-Paket bereits enthalten (`vendor/` ist Teil des AddOns). Wer das Repo direkt klont, statt das Paket ueber REDAXO.org zu laden, fuehrt einmalig `composer install --no-dev` im AddOn-Verzeichnis aus.

## Konfiguration

### Profile anlegen

Unter **AI Platform > Profile** werden Profile fuer jeden Anwendungsfall separat angelegt. Jedes Profil hat einen **Typ** und ein **Modell** mit typspezifischen Einstellungen.

#### Gemeinsame Felder

| Feld | Beschreibung |
|---|---|
| **Profilname** | Eindeutiger Name, z.B. "Claude Text" oder "DALL-E Bilder" |
| **Typ** | Text/Code, Embeddings, Bildgenerierung oder Bildverstaendnis |
| **Provider** | OpenAI, Anthropic, Google, Ollama oder Replicate |
| **API-Key** | API-Schluessel (wird bei Ollama ausgeblendet) |
| **Basis-URL** | Nur bei Ollama sichtbar (Standard: `http://localhost:11434`) |
| **Modell** | Wird automatisch passend zum Provider und Typ vorausgefuellt |

#### Typ: Text/Code

| Einstellung | Beschreibung | Standard |
|---|---|---|
| **Temperature** | 0 = deterministisch, 1 = normal, 2 = kreativ | 1.0 |
| **Max Tokens** | Maximale Antwortlaenge | 4096 |
| **System-Prompt** | Standard-Anweisung, z.B. "Du bist ein hilfreicher Assistent." | leer |

Der System-Prompt wird automatisch bei jedem Aufruf verwendet, kann aber per API ueberschrieben werden.

#### Typ: Embeddings

Embeddings erzeugen numerische Vektoren fuer semantische Suche, Aehnlichkeitsvergleiche und RAG-Workflows.

Typische Modelle sind z.B. `text-embedding-3-small` (OpenAI), `text-embedding-004` (Google) oder `nomic-embed-text` (Ollama).

#### Typ: Bildgenerierung

| Einstellung | Beschreibung | Standard | Nur bei |
|---|---|---|---|
| **Bildgroesse** | z.B. 1024x1024, 1792x1024 | 1024x1024 | alle |
| **Bildqualitaet** | Standard oder HD | Standard | OpenAI |
| **Bildstil** | Vivid oder Natural | Vivid | OpenAI |

Bildqualitaet und Bildstil sind DALL-E-spezifisch und werden bei anderen Providern ausgeblendet.

#### Typ: Bildverstaendnis

| Einstellung | Beschreibung | Standard |
|---|---|---|
| **Temperature** | 0 = deterministisch, 1 = normal, 2 = kreativ | 1.0 |
| **Max Tokens** | Maximale Antwortlaenge | 4096 |
| **Detail-Level** | Auto, Low oder High (High kostet mehr Tokens) | Auto |

Hinweis: Das Detail-Level wird im Profil gespeichert, aber aktuell nicht als API-Option an den Provider gesendet. Bei Symfony AI wird das Detail-Level ueber die Image-Message gesteuert.

### Standard-Profile festlegen

Unter **AI Platform > Einstellungen** wird pro Anwendungsfall ein Standard-Profil gewaehlt. Die Dropdowns zeigen nur Profile des passenden Typs. So kann z.B. Anthropic fuer Text und OpenAI fuer Bildgenerierung verwendet werden.

### Verbindung testen

Beim Bearbeiten eines Profils kann ueber den Button **"API-Verbindung testen"** geprueft werden, ob API-Key und Modell funktionieren. Der Test sendet einen minimalen Request an den Provider.

## API fuer andere AddOns

Alle Profil-Einstellungen (Temperature, Max Tokens, System-Prompt, Bildgroesse etc.) werden automatisch bei jedem API-Aufruf angewendet.

### Textgenerierung

```php
$service = rex_ai_platform_service::getInstance();

// Einfache Textgenerierung - nutzt Standard-Profil mit dessen Temperature,
// Max Tokens und System-Prompt
$text = $service->generateText('Beschreibe REDAXO CMS in 3 Saetzen.');

// System-Prompt ueberschreiben (ueberschreibt den im Profil hinterlegten)
$text = $service->generateText(
    'Uebersetze ins Englische: Hallo Welt',
    'Du bist ein professioneller Uebersetzer.'
);

// Mit bestimmtem Profil (ID)
$text = $service->generateText('Hallo', null, 2);
```

### Bildverstaendnis

```php
$service = rex_ai_platform_service::getInstance();

// Nutzt automatisch Detail-Level, Temperature und Max Tokens aus dem Profil
$description = $service->understandImage(
    'Beschreibe dieses Bild.',
    rex_path::media('foto.jpg')
);

// Alt-Text generieren
$altText = $service->understandImage(
    'Erstelle einen kurzen, beschreibenden Alt-Text fuer dieses Bild.',
    rex_path::media('header.png')
);
```

### Bildgenerierung

```php
$service = rex_ai_platform_service::getInstance();

// Nutzt automatisch Bildgroesse, Qualitaet und Stil aus dem Profil
$imageUrl = $service->generateImage('Ein modernes Logo fuer ein CMS');
```

### Embeddings

```php
$service = rex_ai_platform_service::getInstance();

// Einzelnes Embedding - nutzt Standard-Embedding-Profil
$vector = $service->generateEmbedding('REDAXO ist ein flexibles Open-Source-CMS.');

// Mehrere Embeddings in einem Aufruf
$vectors = $service->generateEmbedding([
    'Artikel ueber CMS-Architektur',
    'Dokumentation zu REDAXO AddOns',
]);
```

### Profil-Optionen manuell nutzen

```php
$service = rex_ai_platform_service::getInstance();

// Alle Einstellungen eines Profils als Options-Array
$options = $service->getProfileOptions($profileId);
// Liefert z.B.: ['temperature' => 0.7, 'max_output_tokens' => 8192] (OpenAI)
// Oder:         ['temperature' => 0.7, 'max_tokens' => 8192] (Anthropic, Google, Ollama)

// Profil-Daten lesen
$profile = $service->getDefaultProfile('text');
// $profile['system_prompt'], $profile['temperature'], etc.

// Profile nach Typ filtern
$textProfiles = $service->getProfiles('text');
$embeddingProfiles = $service->getProfiles('embedding');
$imageProfiles = $service->getProfiles('image_generation');
```

### Direkter Platform-Zugriff (Symfony AI)

```php
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$service = rex_ai_platform_service::getInstance();
$profile = $service->getDefaultProfile('text');
$platform = $service->getPlatform($profile['id']);
$options = $service->getProfileOptions($profile['id']);

$messages = new MessageBag(
    Message::forSystem($profile['system_prompt'] ?? 'Du bist ein hilfreicher Assistent.'),
    Message::ofUser('Was ist REDAXO?'),
);

// Options enthalten Temperature, Max Tokens etc. aus dem Profil
$result = $platform->invoke($profile['model'], $messages, $options);
echo $result->asText();
```

### Agent mit Tool-Support

```php
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$service = rex_ai_platform_service::getInstance();

// Agent erstellt - sammelt automatisch Tools von anderen AddOns
$agent = $service->createAgent('text');

$messages = new MessageBag(
    Message::ofUser('Wie ist das Wetter in Berlin?'),
);
$result = $agent->call($messages);
echo $result->getContent();
```

## MCP-Server

Das AddOn stellt einen MCP-Server (Model Context Protocol) als HTTP-Endpoint bereit. Damit koennen KI-Tools wie Claude Desktop, Cursor, Windsurf oder andere MCP-Clients direkt auf REDAXO-Funktionen zugreifen.

### MCP-Server aktivieren

1. **AI Platform > MCP Server > Einstellungen** oeffnen
2. **MCP Server aktiv** auf "Aktiv" setzen
3. Optional: **Server-Beschreibung** eintragen - diese wird als `instructions` an MCP-Clients gesendet und beschreibt, wofuer der Server gedacht ist und welche Daten die REDAXO-Instanz verwaltet
4. **Speichern**
5. Den angezeigten **Endpoint-URL** notieren

Das Untermenue **MCP Server** ist im Backend in drei Tabs gegliedert:

| Tab | Inhalt |
|---|---|
| **Einstellungen** | MCP aktivieren, Server-Beschreibung, Discovery-URLs, Liste der registrierten Tools mit Auth-Status und **Aktiv-Schalter pro Tool** (deaktivierte Tools werden Clients nicht angezeigt und nicht ausgefuehrt) |
| **OAuth-Clients** | Liste aller (manuell oder via DCR) registrierten OAuth-Clients, Anlegen + Loeschen |
| **Scope-Mapping** | Welche Scopes bekommen Nutzer aufgrund ihrer YCom-Gruppenmitgliedschaft |

### Endpoint-Pfade

| Pfad | Zweck |
|---|---|
| `POST /mcp` | MCP JSON-RPC Endpoint |
| `GET /.well-known/oauth-protected-resource` | Discovery: zeigt MCP-Clients auf den Authorization-Server |
| `GET /.well-known/oauth-authorization-server` | Discovery: OAuth-2.1-Endpunkt-Metadaten |
| `GET/POST /oauth/authorize` | OAuth-2.1-Login (gegen YCom) + Consent-Screen |
| `POST /oauth/token` | Token-Endpoint (`authorization_code` + `refresh_token`) |
| `POST /oauth/register` | Dynamic Client Registration (RFC 7591) |

Bei einer lokalen Entwicklungsumgebung z.B.:

```
https://redaxo.localhost/mcp
```

### Authentifizierung

Auth-Modi:

- **Public Tools** (`public: true`) — ohne Authentifizierung aufrufbar. Das eingebaute `redaxo_status` Tool ist als public markiert, damit Monitoring-Tools und Discovery ohne Account funktionieren.
- **Geschuetzte Tools** — erfordern einen gueltigen OAuth-2.1-Access-Token mit den deklarierten Scopes.

> **Wichtig fuer geschuetzte Tools:** MCP-Clients starten den OAuth-Login erst, wenn sie ein `401` erhalten. Solange der Server anonyme Anfragen mit `200` beantwortet (Default), verbinden sich Clients wie Claude Desktop **anonym** und sehen ausschliesslich public Tools — geschuetzte Tools tauchen gar nicht erst in der Tool-Liste auf. Damit ein Client sich einloggt, unter **MCP Server > Einstellungen** die Option **Authentifizierung erforderlich** auf "Ja" stellen (Config-Key `mcp_require_auth`). Dann antwortet `/mcp` schon beim Verbinden mit `401`, der Client durchlaeuft den YCom-Login und der ausgestellte Token traegt die Scopes des Nutzers — erst dann werden passende geschuetzte Tools sichtbar. Nach dem Umstellen den Connector im Client neu verbinden.

Der OAuth-2.1-Stack laeuft komplett ueber Standard-Pfade:

| Pfad | Methode | Zweck |
|---|---|---|
| `/.well-known/oauth-protected-resource` | GET | RFC-9728-Discovery (zeigt MCP-Clients auf den Authorization-Server) |
| `/.well-known/oauth-authorization-server` | GET | RFC-8414-Metadata (issuer, endpoints, supported methods) |
| `/oauth/authorize` | GET/POST | User-Login (gegen YCom) + Consent-Screen, gibt einen Code mit PKCE-Challenge aus |
| `/oauth/token` | POST | `authorization_code`-Exchange + `refresh_token`-Rotation |
| `/oauth/register` | POST | Dynamic Client Registration (RFC 7591, auto-approve fuer public clients) |

Beim Aufruf eines geschuetzten Tools ohne / mit ungueltigem Token antwortet `/mcp` mit HTTP `401` und einem RFC-6750-konformen Header:

```
WWW-Authenticate: Bearer realm="MCP", resource="https://example.org/.well-known/oauth-protected-resource", error="invalid_token", ...
```

`mcp-remote` und andere MCP-Clients folgen automatisch der Discovery-URL und starten den OAuth-Flow. Im Browser-Fenster meldet sich der Nutzer mit YCom-Credentials an und sieht einen Consent-Screen mit den effektiven Scopes (Schnittmenge aus angefragten Scopes und Gruppen-Mapping). Nach Zustimmung wird `mcp-remote` automatisch zurueckgeleitet, tauscht den Code mit PKCE-Verifier gegen ein Access+Refresh-Token-Paar ein und legt es lokal ab.

> **Breaking Change ab 1.0.0-beta2:** Der frueher in `rex_config` hinterlegte feste Bearer-Token wurde entfernt. Bestehende Claude-Desktop- / Cursor-Setups, die diesen Token nutzen, muessen die `--header`-Zeile aus ihrer Config streichen — `mcp-remote` uebernimmt die OAuth-Negotiation jetzt selbststaendig.

### OAuth-Setup im Backend

Empfohlener Ablauf, wenn der MCP-Server in Produktion gehen soll:

1. **Gruppe(n) in YCom anlegen.** Die normale YCom-Gruppen-Verwaltung. Beispielsweise eine Gruppe `mcp-users` fuer Standard-Zugriff.
2. **Scopes pro Gruppe vergeben** unter **AI Platform > MCP Server > Scope-Mapping**. Pro YCom-Gruppe Checkboxen fuer alle bekannten Scopes. Es gibt keine eingebauten Scopes — die auswaehlbaren Scopes stammen ausschliesslich aus AddOns, die sie ueber den Extension Point `AI_PLATFORM_OAUTH_SCOPES` ankuendigen. Ein geschuetztes Tool ist fuer eine Gruppe sichtbar/aufrufbar, sobald die Gruppe alle `requiredScopes` dieses Tools gemappt hat.
3. **YCom-Nutzer in die Gruppe(n) eintragen** ueber die YCom-User-Verwaltung.
4. **OAuth-Client anlegen** unter **AI Platform > MCP Server > OAuth-Clients**, falls eine feste Client-Definition gewuenscht ist. Alternativ koennen MCP-Clients sich via DCR (`POST /oauth/register`) selbst registrieren — `mcp-remote` macht das automatisch beim ersten Verbindungsaufbau.
   - **public client** (PKCE only): geeignet fuer mcp-remote, Cursor, Claude Desktop. Kein Secret.
   - **confidential client** (client_secret): geeignet fuer Server-zu-Server-Setups. Das Secret wird nur einmal nach dem Anlegen angezeigt.
5. **Test mit curl** (oder direkt mit `mcp-remote`):
   ```bash
   # Discovery
   curl -s https://example.org/.well-known/oauth-protected-resource

   # Public Tool ohne Auth
   curl -s -X POST https://example.org/mcp \
       -H "Content-Type: application/json" \
       -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"redaxo_status","arguments":{}}}'
   ```

Geschuetzte Tools koennen erst genutzt werden, sobald ein User per OAuth eingeloggt hat. Im Browser geht das ueber den Login-Screen unter `/oauth/authorize`, automatisierte Tests muessen den Flow durchspielen (siehe `.claude/tests/oauth-authorize-test.sh` im Repo als Referenz).

### Eingebautes Tool: redaxo_status

Das AddOn registriert automatisch das Tool `redaxo_status` (public), das folgende Informationen ueber die REDAXO-Instanz liefert:

- REDAXO-Version, PHP-Version, Datenbank-Version
- Server-URL und Server-Name
- Sprachen (alle CMS-Sprachen)
- Anzahl Artikel, Kategorien, Medien, Benutzer
- Debug- und Safe-Mode Status
- Liste aller installierten AddOns mit Versionen und Plugins

### MCP-Clients anbinden

Es gibt zwei Wege — je nachdem, **wer** die Verbindung aufbaut.

> **Pfad immer mit `/mcp`!** Der Client postet die JSON-RPC an die eingetragene URL. Endet sie nur auf der Domain, gehen die Aufrufe an die Startseite (HTML) und schlagen fehl.
>
> **Geschuetzte Tools** erscheinen nur, wenn der Client sich per OAuth einloggt. Dazu unter **MCP Server > Einstellungen** die Option **Authentifizierung erforderlich** auf „Ja" setzen — sonst verbindet sich der Client anonym und sieht ausschliesslich `public` Tools.

#### Nativer Remote-Connector (empfohlen, produktiv)

Claude Desktop / claude.ai (Custom Connector) und Claude Code sprechen den `/mcp`-Endpoint direkt und uebernehmen OAuth selbst — kein `mcp-remote` noetig.

- **Claude Desktop:** Einstellungen → Connectors → „Custom Connector hinzufuegen" → URL `https://deine-domain.de/mcp`
- **Claude Code:** `claude mcp add --transport http redaxo https://deine-domain.de/mcp`

Wichtig: Bei diesem Weg vermittelt der Client den OAuth-Flow ueber die Cloud des Anbieters. Der Server muss daher **oeffentlich erreichbar** sein — eine reine `*.localhost`-Domain funktioniert NICHT (Fehler „Couldn't register with sign-in service"). Fuer lokale Tests einen Tunnel davorschalten (siehe unten).

#### mcp-remote (stdio-Bridge)

Fuer Clients, die nur stdio sprechen, oder fuer einen lokalen Server: `mcp-remote` laeuft lokal auf deinem Rechner und erreicht daher auch lokale Domains.

`claude_desktop_config.json` (macOS: `~/Library/Application Support/Claude/`, Windows: `%APPDATA%\Claude\`):

```json
{
    "mcpServers": {
        "redaxo": {
            "command": "npx",
            "args": ["-y", "mcp-remote", "https://deine-domain.de/mcp"]
        }
    }
}
```

Beim ersten Verbinden oeffnet `mcp-remote` einen Browser-Tab fuer die YCom-Anmeldung. Cursor / Windsurf analog in `.cursor/mcp.json`. Claude Code als stdio-Variante: `claude mcp add redaxo -- npx -y mcp-remote https://deine-domain.de/mcp`.

Lokaler Server mit selbstsigniertem / mkcert-Zertifikat — Node das CA-Root mitgeben (sauberer als TLS abschalten):

```json
"env": { "NODE_EXTRA_CA_CERTS": "<Ausgabe von: mkcert -CAROOT>/rootCA.pem" }
```

(Ersatzweise `"NODE_TLS_REJECT_UNAUTHORIZED": "0"`.)

#### Lokal testen via Tunnel (ngrok)

Damit der native Connector gegen eine lokale Instanz funktioniert, einen oeffentlichen HTTPS-Tunnel davorschalten:

```bash
ngrok http https://redaxo.localhost --url=https://dein-name.ngrok-free.dev
```

HTTPS-Upstream verwenden (SNI/Zertifikat passen, richtiger vhost wird getroffen). Discovery, Issuer und OAuth-Redirects uebernehmen automatisch den oeffentlichen Host (aus `X-Forwarded-Host`). Die Tunnel-URL inkl. `/mcp` im Connector eintragen.

### MCP-Protokoll

Der Server implementiert das MCP-Protokoll (JSON-RPC 2.0) mit folgenden Methoden:

| Methode | Beschreibung |
|---|---|
| `initialize` | Handshake, gibt Server-Info und Capabilities zurueck |
| `tools/list` | Listet alle verfuegbaren Tools |
| `tools/call` | Fuehrt ein Tool aus |
| `ping` | Health-Check |

#### Beispiel-Request

```bash
curl -X POST "https://deine-domain.de/mcp" \
    -H "Content-Type: application/json" \
    -d '{
        "jsonrpc": "2.0",
        "id": 1,
        "method": "tools/list",
        "params": {}
    }'
```

Anonyme Aufrufer sehen ueber `tools/list` nur Tools, die als `public: true` markiert sind. Geschuetzte Tools antworten beim Aufruf mit `401` plus `WWW-Authenticate`-Header, der den Client auf die OAuth-Discovery zeigt — Standard-Clients wie `mcp-remote` starten daraufhin automatisch den Login-Flow.

## Tools registrieren (fuer AddOn-Entwickler)

Andere AddOns koennen Tools ueber den Extension Point `AI_PLATFORM_MCP_TOOLS` bereitstellen. Diese Tools werden sowohl im MCP-Server als auch im Agent-System verfuegbar.

### Beispiel: Ein einfaches Tool

```php
// In boot.php oder lib/ des eigenen AddOns:
rex_extension::register('AI_PLATFORM_MCP_TOOLS', function (rex_extension_point $ep) {
    $tools = $ep->getSubject();

    $tools['redaxo_article_search'] = new rex_ai_mcp_tool(
        name: 'redaxo_article_search',
        description: 'Sucht nach REDAXO-Artikeln anhand eines Suchbegriffs.',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Der Suchbegriff',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximale Anzahl der Ergebnisse (Standard: 10)',
                ],
            ],
            'required' => ['query'],
        ],
        handler: function (array $arguments, rex_ai_mcp_context $context): string {
            // $context->getYcomUser() / $context->hasScope('...') verfuegbar.
            // Bei anonymem Zugriff (kein gueltiger Token) ist der Context
            // anonymous — geschuetzte Tools werden dann gar nicht erst erreicht.
            $query = $arguments['query'];
            $limit = $arguments['limit'] ?? 10;

            $sql = rex_sql::factory();
            $sql->setQuery(
                'SELECT id, name FROM ' . rex::getTable('article')
                . ' WHERE name LIKE ? LIMIT ?',
                ['%' . $query . '%', $limit]
            );

            $results = [];
            foreach ($sql as $row) {
                $results[] = [
                    'id' => $row->getValue('id'),
                    'name' => $row->getValue('name'),
                ];
            }

            return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        },
        public: false,
        // Eigener Scope des AddOns — vorher via AI_PLATFORM_OAUTH_SCOPES
        // ankuendigen, dann im Backend einer YCom-Gruppe zuordnen.
        requiredScopes: ['redaxo:articles:read'],
    );

    return $tools;
});
```

### Tool-Konstruktor-Parameter

| Parameter | Pflicht | Beschreibung |
|---|---|---|
| `name` | ja | Eindeutiger Tool-Name |
| `description` | ja | Beschreibung, wird MCP-Clients als Tool-Hint angezeigt |
| `inputSchema` | ja | JSON-Schema fuer die Argumente |
| `handler` | ja | `function (array $arguments, rex_ai_mcp_context $context): mixed` |
| `public` | nein | `true` macht das Tool ohne Authentifizierung aufrufbar (Default: `false`) |
| `requiredScopes` | nein | Liste der Scopes, die der Caller besitzen muss. Wird beim Tool-Aufruf gegen die effektiven Scopes des angemeldeten Nutzers geprueft (aus seinen YCom-Gruppen, siehe Scope-Mapping) |

Im Handler kann ueber `$context` der angemeldete YCom-User abgefragt werden — siehe `lib/rex_ai_mcp_context.php` fuer die volle API.

### Beispiel: Public Tool ohne Auth

```php
rex_extension::register('AI_PLATFORM_MCP_TOOLS', function (rex_extension_point $ep) {
    $tools = $ep->getSubject();

    $tools['redaxo_media_info'] = new rex_ai_mcp_tool(
        name: 'redaxo_media_info',
        description: 'Gibt Informationen zu einer Mediendatei zurueck.',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'filename' => [
                    'type' => 'string',
                    'description' => 'Der Dateiname der Mediendatei',
                ],
            ],
            'required' => ['filename'],
        ],
        handler: function (array $arguments, rex_ai_mcp_context $context): string {
            $media = rex_media::get($arguments['filename']);
            if (!$media) {
                return 'Mediendatei nicht gefunden: ' . $arguments['filename'];
            }

            return json_encode([
                'filename' => $media->getFileName(),
                'title' => $media->getTitle(),
                'type' => $media->getType(),
                'size' => $media->getFormattedSize(),
                'width' => $media->getWidth(),
                'height' => $media->getHeight(),
                'url' => $media->getUrl(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        },
        public: true,
    );

    return $tools;
});
```

## Extension Points

| Extension Point | Beschreibung | Subject |
|---|---|---|
| `AI_PLATFORM_MCP_TOOLS` | Tools fuer den MCP-Server registrieren | `array<string, rex_ai_mcp_tool>` |
| `AI_PLATFORM_AGENT_TOOLS` | Tools fuer den Agent registrieren | `array<object>` (Symfony AI Tool-Objekte) |
| `AI_PLATFORM_OAUTH_SCOPES` | Eigene Scopes fuer das Scope-Mapping ankuendigen | `array<string, string>` (scope → description) |

### Beispiel: Eigene Scopes registrieren

```php
rex_extension::register('AI_PLATFORM_OAUTH_SCOPES', function (rex_extension_point $ep) {
    $scopes = $ep->getSubject();
    $scopes['redaxo:articles:read'] = 'Read REDAXO articles via MCP tools';
    $scopes['redaxo:articles:write'] = 'Create/update REDAXO articles via MCP tools';
    return $scopes;
});
```

Die so registrierten Scopes erscheinen automatisch im Backend unter **MCP Server > Scope-Mapping** als auswaehlbare Checkboxen.

## Systemvoraussetzungen

- REDAXO >= 5.18
- PHP >= 8.2
- Composer (fuer die Installation der Symfony AI Bibliotheken)

## Lizenz

MIT License
