# AI Platform - REDAXO AddOn

Die **AI Platform** ist das zentrale AddOn fuer die Integration von KI-Diensten in REDAXO. Es bietet eine einheitliche Schnittstelle zu verschiedenen LLM-Providern und stellt einen MCP-Server bereit, ueber den andere AddOns Tools fuer KI-Agenten anbieten koennen.

## Features

- Verwaltung mehrerer AI-Provider und API-Keys ueber Profile
- Pro Profil ein Typ (Text/Code, Bildgenerierung, Bildverstaendnis) mit typspezifischen Einstellungen
- Automatische Modell-Vorauswahl je nach Provider und Typ
- API-Verbindungstest direkt im Backend
- MCP-Server (Model Context Protocol) als HTTP-Endpoint mit konfigurierbarer Beschreibung
- Eingebautes `redaxo_status` MCP-Tool (Systeminfos der REDAXO-Instanz)
- Extension Points fuer andere AddOns (MCP-Tools, Agent-Tools)
- Basiert auf [Symfony AI](https://symfony.com/ai) (v0.6)

## Unterstuetzte Provider

| Provider | Text | Bildgenerierung | Bildverstaendnis |
|---|---|---|---|
| **OpenAI** | `gpt-4o`, `gpt-4o-mini`, `o1`, `o3-mini` | `dall-e-3`, `gpt-image-1` | `gpt-4o`, `gpt-4o-mini` |
| **Anthropic** | `claude-sonnet-4-20250514`, `claude-opus-4-20250514`, `claude-3-7-sonnet-latest` | - | `claude-sonnet-4-20250514`, `claude-opus-4-20250514` |
| **Google** | `gemini-2.5-flash`, `gemini-2.5-pro` | `gemini-2.0-flash-exp` | `gemini-2.5-flash`, `gemini-2.5-pro` |
| **Ollama** | `llama3.2`, `mistral`, `deepseek-r1` u.a. | - | `llava`, `llama3.2-vision` |

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
| **Typ** | Text/Code, Bildgenerierung oder Bildverstaendnis |
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

1. **AI Platform > MCP Server** oeffnen
2. **MCP Server aktiv** auf "Aktiv" setzen
3. Optional: **Server-Beschreibung** eintragen - diese wird als `instructions` an MCP-Clients gesendet und beschreibt, wofuer der Server gedacht ist und welche Daten die REDAXO-Instanz verwaltet
4. **Speichern**
5. Den angezeigten **Endpoint-URL** notieren

### Endpoint-Pfade

| Pfad | Zweck |
|---|---|
| `POST /mcp` | MCP JSON-RPC Endpoint |
| `GET /.well-known/oauth-protected-resource` | Discovery: zeigt MCP-Clients auf den Authorization-Server |
| `GET /.well-known/oauth-authorization-server` | Discovery: OAuth-2.1-Endpunkt-Metadaten |
| `GET /oauth/{authorize,token,register}` | OAuth-Flow (Phase 2, aktuell `501 Not Implemented`) |
| `POST /index.php?rex-api-call=ai_mcp` | Deprecated-Legacy-Endpoint, bleibt aus Kompatibilitaetsgruenden erreichbar |

Bei einer lokalen Entwicklungsumgebung z.B.:

```
https://redaxo.localhost/mcp
```

### Authentifizierung

Auth-Modi:

- **Public Tools** (`public: true`) — ohne Authentifizierung aufrufbar. Das eingebaute `redaxo_status` Tool ist als public markiert, damit Monitoring-Tools und Discovery ohne Account funktionieren.
- **Geschuetzte Tools** — erfordern einen gueltigen OAuth-2.1-Access-Token mit den deklarierten Scopes.

Beim Aufruf eines geschuetzten Tools ohne Token antwortet der Server mit HTTP `401` und sendet einen `WWW-Authenticate`-Header, der MCP-Clients auf die Discovery-URL zeigt. Der Client startet daraufhin automatisch den OAuth-Flow.

Der OAuth-2.1-Stack (Authorization Code + PKCE + Refresh Tokens + Dynamic Client Registration, YCom als Identity-Provider, Scopes ueber YCom-Gruppen) befindet sich in Phase 2 der Implementierung. Solange dieser nicht ausgerollt ist, sind nur public Tools nutzbar.

> **Breaking Change ab 1.0.0-beta2:** Der frueher in `rex_config` hinterlegte feste Bearer-Token wurde entfernt. Bestehende Claude-Desktop- / Cursor-Setups, die diesen Token nutzen, muessen die `--header`-Zeile aus ihrer Config streichen und auf den OAuth-Flow warten bzw. nur public Tools aufrufen.

### Eingebautes Tool: redaxo_status

Das AddOn registriert automatisch das Tool `redaxo_status` (public), das folgende Informationen ueber die REDAXO-Instanz liefert:

- REDAXO-Version, PHP-Version, Datenbank-Version
- Server-URL und Server-Name
- Sprachen (alle CMS-Sprachen)
- Anzahl Artikel, Kategorien, Medien, Benutzer
- Debug- und Safe-Mode Status
- Liste aller installierten AddOns mit Versionen und Plugins

### Einbindung in Claude Desktop

In der Claude Desktop Konfigurationsdatei (`claude_desktop_config.json`):

**macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
**Windows:** `%APPDATA%\Claude\claude_desktop_config.json`

Claude Desktop unterstuetzt HTTP-basierte MCP-Server ueber `mcp-remote` als Proxy. Installiere zuerst `mcp-remote`:

```bash
npm install -g mcp-remote
```

Dann in der `claude_desktop_config.json`:

```json
{
    "mcpServers": {
        "redaxo": {
            "command": "npx",
            "args": [
                "mcp-remote",
                "https://deine-domain.de/mcp"
            ]
        }
    }
}
```

Sobald der OAuth-Flow aktiv ist, oeffnet `mcp-remote` beim ersten Verbindungsaufbau automatisch einen Browser-Tab fuer die YCom-Anmeldung.

#### Lokaler Server (z.B. https://redaxo.localhost)

Bei einem lokalen Server mit selbstsigniertem SSL-Zertifikat muss die Zertifikatspruefung deaktiviert werden:

```json
{
    "mcpServers": {
        "redaxo": {
            "command": "npx",
            "args": [
                "mcp-remote",
                "https://redaxo.localhost/mcp"
            ],
            "env": {
                "NODE_TLS_REJECT_UNAUTHORIZED": "0"
            }
        }
    }
}
```

#### Lokaler Server ohne HTTPS

```json
{
    "mcpServers": {
        "redaxo": {
            "command": "npx",
            "args": [
                "mcp-remote",
                "http://localhost:8080/mcp"
            ]
        }
    }
}
```

### Einbindung in Cursor / Windsurf

In der Cursor-Konfiguration (`.cursor/mcp.json` im Projektverzeichnis):

```json
{
    "mcpServers": {
        "redaxo": {
            "command": "npx",
            "args": [
                "mcp-remote",
                "https://deine-domain.de/mcp"
            ]
        }
    }
}
```

### Einbindung in Claude Code (CLI)

```bash
claude mcp add redaxo -- npx mcp-remote "https://deine-domain.de/mcp"
```

Fuer lokale Server mit selbstsigniertem Zertifikat:

```bash
NODE_TLS_REJECT_UNAUTHORIZED=0 claude mcp add redaxo -- npx mcp-remote "https://redaxo.localhost/mcp"
```

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

Anonyme Aufrufer sehen nur Tools, die als `public: true` markiert sind. Geschuetzte Tools antworten beim Aufruf mit `401` plus `WWW-Authenticate`-Header, der den Client zum OAuth-Flow leitet.

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
            // $context->getYcomUser() / $context->hasScope('...') verfuegbar,
            // sobald Phase 2 die Auth aktiviert. Im Phase-1-Modus ist der
            // Context anonymous und reicht durch.
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
        requiredScopes: ['mcp:tools:call'],
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
| `requiredScopes` | nein | Liste der Scopes, die der Caller besitzen muss (greift erst mit OAuth in Phase 2) |

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

## Systemvoraussetzungen

- REDAXO >= 5.18
- PHP >= 8.2
- Composer (fuer die Installation der Symfony AI Bibliotheken)

## Lizenz

MIT License
