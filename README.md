# KI Platform - REDAXO AddOn

Die **KI Platform** ist das zentrale AddOn fuer die Integration von KI-Diensten in REDAXO. Es bietet eine einheitliche Schnittstelle zu verschiedenen LLM-Providern, stellt einen MCP-Server bereit, ueber den andere AddOns Tools fuer KI-Agenten anbieten koennen, und nimmt Änderungswuensche von Agenten an, die erst nach redaktioneller Freigabe wirksam werden.

## Features

- Verwaltung mehrerer AI-Provider und API-Keys ueber Profile
- Pro Profil ein Typ (Text/Code, Embeddings, Bildgenerierung, Bildverstaendnis) mit typspezifischen Einstellungen
- Automatische Modell-Vorauswahl je nach Provider und Typ
- API-Verbindungstest direkt im Backend
- MCP-Server (Model Context Protocol) als HTTP-Endpoint auf `/mcp` mit OAuth-2.1-Discovery
- OAuth-2.1-Autorisierung mit PKCE + Refresh Tokens + Dynamic Client Registration, YCom als Identity-Provider
- Scope-System mit Mapping `YCom-Gruppe → Scopes` ueber das Backend
- Eingebautes `redaxo_status` MCP-Tool (Systeminfos der REDAXO-Instanz, public)
- Änderungswuensche: Agenten und AddOns reichen Inhaltsaenderungen ein, ein Redakteur gibt sie frei — fuer Slices, Artikel, Kategorien, Metainfo, Medien und YForm-Datensaetze
- Extension Points fuer andere AddOns (MCP-Tools, Agent-Tools, eigene Scopes, eigene Änderungstypen, eigene LLM-Provider)
- Basiert auf [Symfony AI](https://symfony.com/ai) (v0.6)

## Unterstuetzte Provider

| Provider | Text | Embeddings | Bildgenerierung | Bildverstaendnis |
|---|---|---|---|---|
| **OpenAI** | `gpt-4o`, `gpt-4o-mini`, `o1`, `o3-mini` | `text-embedding-3-small`, `text-embedding-3-large`, `text-embedding-ada-002` | `dall-e-3`, `gpt-image-1` | `gpt-4o`, `gpt-4o-mini` |
| **Anthropic** | `claude-sonnet-4-20250514`, `claude-opus-4-20250514`, `claude-3-7-sonnet-latest` | - | - | `claude-sonnet-4-20250514`, `claude-opus-4-20250514` |
| **Google** | `gemini-2.5-flash`, `gemini-2.5-pro` | `text-embedding-004` | `gemini-2.0-flash-exp` | `gemini-2.5-flash`, `gemini-2.5-pro` |
| **Ollama** | `llama3.2`, `mistral`, `deepseek-r1` u.a. | `nomic-embed-text`, `mxbai-embed-large` | - | `llava`, `llama3.2-vision` |
| **OpenAI-kompatibel** | beliebige Modellnamen des Servers | beliebige Modellnamen des Servers | - | beliebige Modellnamen des Servers |

Bei Ollama ist nur die Basis-URL Pflicht (Standard: `http://localhost:11434`). Der API-Key ist dort optional: bleibt er leer, wird kein `Authorization`-Header gesendet -- gesetzt, geht er als Bearer-Token mit, wie es ein per Reverse Proxy abgesicherter Ollama-Server erwartet.

**OpenAI-kompatibel** spricht das klassische Chat-Completions-Protokoll gegen eine frei eingetragene Basis-URL und deckt damit selbstgehostete und fremde Endpunkte ab: Open WebUI, LiteLLM, vLLM, LM Studio, OpenRouter, Groq, DeepSeek und alles andere, was diese API anbietet. Die Basis-URL darf mit oder ohne `/v1` eingetragen werden. Einen gepflegten Modellkatalog gibt es dort naturgemaess nicht -- statt einer Auswahl erscheint das Textfeld, das jeden Namen annimmt, den der Server kennt.

### Eigene Provider ergaenzen

Die Provider stehen in `FriendsOfRedaxo\AiPlatform\ProviderRegistry` -- ein Eintrag pro Provider, und alles zu einem Provider in genau diesem Eintrag: Label fuer die Auswahl, benoetigte Felder, Modellkatalog und die Closure, die die Symfony-AI-Platform baut. Symfony AI liefert dafuer knapp 40 Bridge-Pakete (`symfony/ai-mistral-platform`, `symfony/ai-open-router-platform`, `symfony/ai-bedrock-platform`, `symfony/ai-vertex-ai-platform` und weitere).

Ein anderes AddOn haengt seinen Provider ueber den Extension Point `AI_PLATFORM_PROVIDERS` an -- ohne Fork und ohne Release dieses AddOns:

```php
use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use Symfony\AI\Platform\Bridge\Mistral\ModelCatalog as MistralCatalog;
use Symfony\AI\Platform\Bridge\Mistral\PlatformFactory as MistralFactory;

rex_extension::register(ProviderRegistry::EXTENSION_POINT, function (rex_extension_point $ep) {
    $providers = $ep->getSubject();

    $providers['mistral'] = [
        'label' => 'Mistral',
        // Felder, die das Profilformular fuer diesen Provider zeigt:
        // 'api_key', 'base_url', 'image_quality', 'image_style'
        'fields' => ['api_key'],
        // Vorbelegung des Modellfelds je Profiltyp
        'defaults' => ['text' => 'mistral-large-latest'],
        'catalog' => static fn () => new MistralCatalog(),
        'factory' => static fn (array $profile) => MistralFactory::create($profile['api_key']),
    ];

    return $providers;
});
```

Die Modellauswahl im Profilformular kommt aus `catalog` -- gefiltert nach den Faehigkeiten, die der gewaehlte Typ braucht. Die Auswahl hat immer den Eintrag "eigener Modellname", der ein Textfeld freischaltet: die Kataloge sind nicht vollstaendig (bei Ollama fehlt `llama3.2-vision` etwa ganz), und eine geschlossene Liste wuerde funktionierende Modellnamen verbieten. Kennt ein Provider fuer den Typ keine Modelle, entfaellt die Auswahl und es bleibt das Textfeld.

## Installation

1. AddOn im REDAXO-Installer herunterladen oder in `redaxo/src/addons/ai_platform/` ablegen
2. AddOn in REDAXO installieren und aktivieren

Die Symfony-AI-Abhaengigkeiten sind im Release-Paket bereits enthalten (`vendor/` ist Teil des AddOns). Wer das Repo direkt klont, statt das Paket ueber REDAXO.org zu laden, fuehrt einmalig `composer install --no-dev` im AddOn-Verzeichnis aus.

## Konfiguration

### Profile anlegen

Unter **KI Platform > Profile** werden Profile fuer jeden Anwendungsfall separat angelegt. Jedes Profil hat einen **Typ** und ein **Modell** mit typspezifischen Einstellungen.

#### Gemeinsame Felder

| Feld | Beschreibung |
|---|---|
| **Profilname** | Eindeutiger Name, z.B. "Claude Text" oder "DALL-E Bilder" |
| **Typ** | Text/Code, Embeddings, Bildgenerierung oder Bildverstaendnis |
| **Provider** | OpenAI, Anthropic, Google, Ollama oder OpenAI-kompatibel -- weitere lassen sich per Extension Point ergaenzen |
| **API-Key** | API-Schluessel; bei Ollama und OpenAI-kompatibel optional (Bearer-Token fuer abgesicherte Server) |
| **Basis-URL** | Bei Ollama und OpenAI-kompatibel sichtbar (Ollama-Standard: `http://localhost:11434`) |
| **Modell** | Auswahl der Modelle, die der Provider fuer diesen Typ kennt, passend vorausgewaehlt; "eigener Modellname" schaltet ein Textfeld fuer jeden anderen Namen frei |

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

Unter **KI Platform > Einstellungen** wird pro Anwendungsfall ein Standard-Profil gewaehlt. Die Dropdowns zeigen nur Profile des passenden Typs. So kann z.B. Anthropic fuer Text und OpenAI fuer Bildgenerierung verwendet werden.

### Verbindung testen

Beim Bearbeiten eines Profils kann ueber den Button **"API-Verbindung testen"** geprueft werden, ob API-Key und Modell funktionieren. Der Test sendet einen minimalen Request an den Provider.

## API fuer andere AddOns

Alle Profil-Einstellungen (Temperature, Max Tokens, System-Prompt, Bildgroesse etc.) werden automatisch bei jedem API-Aufruf angewendet.

### Textgenerierung

```php
$service = FriendsOfRedaxo\AiPlatform\Service::getInstance();

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
$service = FriendsOfRedaxo\AiPlatform\Service::getInstance();

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
$service = FriendsOfRedaxo\AiPlatform\Service::getInstance();

// Nutzt automatisch Bildgroesse, Qualitaet und Stil aus dem Profil
$imageUrl = $service->generateImage('Ein modernes Logo fuer ein CMS');
```

### Embeddings

```php
$service = FriendsOfRedaxo\AiPlatform\Service::getInstance();

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
$service = FriendsOfRedaxo\AiPlatform\Service::getInstance();

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

$service = FriendsOfRedaxo\AiPlatform\Service::getInstance();
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

$service = FriendsOfRedaxo\AiPlatform\Service::getInstance();

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

1. **KI Platform > MCP Server > Einstellungen** oeffnen
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
2. **Scopes pro Gruppe vergeben** unter **KI Platform > MCP Server > Scope-Mapping**. Pro YCom-Gruppe Checkboxen fuer alle bekannten Scopes. Es gibt keine eingebauten Scopes — die auswaehlbaren Scopes stammen ausschliesslich aus AddOns, die sie ueber den Extension Point `AI_PLATFORM_OAUTH_SCOPES` ankuendigen. Ein geschuetztes Tool ist fuer eine Gruppe sichtbar/aufrufbar, sobald die Gruppe alle `requiredScopes` dieses Tools gemappt hat.
3. **YCom-Nutzer in die Gruppe(n) eintragen** ueber die YCom-User-Verwaltung.
4. **OAuth-Client anlegen** unter **KI Platform > MCP Server > OAuth-Clients**, falls eine feste Client-Definition gewuenscht ist. Alternativ koennen MCP-Clients sich via DCR (`POST /oauth/register`) selbst registrieren — `mcp-remote` macht das automatisch beim ersten Verbindungsaufbau.
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
use FriendsOfRedaxo\AiPlatform\Mcp\Tool;
use FriendsOfRedaxo\AiPlatform\Mcp\Context;

rex_extension::register('AI_PLATFORM_MCP_TOOLS', function (rex_extension_point $ep) {
    $tools = $ep->getSubject();

    $tools['redaxo_article_search'] = new Tool(
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
        handler: function (array $arguments, Context $context): string {
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
| `handler` | ja | `function (array $arguments, FriendsOfRedaxo\AiPlatform\Mcp\Context $context): mixed` |
| `public` | nein | `true` macht das Tool ohne Authentifizierung aufrufbar (Default: `false`) |
| `requiredScopes` | nein | Liste der Scopes, die der Caller besitzen muss. Wird beim Tool-Aufruf gegen die effektiven Scopes des angemeldeten Nutzers geprueft (aus seinen YCom-Gruppen, siehe Scope-Mapping) |

Im Handler kann ueber `$context` der angemeldete YCom-User abgefragt werden — siehe `lib/Mcp/Context.php` fuer die volle API.

### Beispiel: Public Tool ohne Auth

```php
use FriendsOfRedaxo\AiPlatform\Mcp\Tool;
use FriendsOfRedaxo\AiPlatform\Mcp\Context;

rex_extension::register('AI_PLATFORM_MCP_TOOLS', function (rex_extension_point $ep) {
    $tools = $ep->getSubject();

    $tools['redaxo_media_info'] = new Tool(
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
        handler: function (array $arguments, Context $context): string {
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

## Änderungswünsche (Change Requests)

Agenten und AddOns lesen Inhalte oft nur — schreiben sollen sie aber trotzdem können. Statt ihnen dafür Schreibrechte zu geben, reichen sie einen **Änderungswunsch** ein: ein Datensatz, der beschreibt, was geändert werden soll, warum, und wie der Stand zum Zeitpunkt der Einreichung aussah. Geschrieben wird erst, wenn ein Redakteur im Backend freigibt.

Der Menuepunkt **KI Änderungen** liegt neben Struktur und Medienpool — aber nur, wenn die Funktion unter *KI Platform > Einstellungen* aktiv ist. Er besteht aus einer einzigen Seite, dem Eingang; Einstellungen und Anleitung liegen unter *KI Platform*, wo der uebrige Administrationsbereich und die uebrige Dokumentation des AddOns schon sind.

Ist sie inaktiv, ist sie auf allen Wegen inaktiv, nicht nur im Menue: der Menuepunkt verschwindet, ein direkter Aufruf per Lesezeichen oder getippter URL zeigt nur einen Hinweis, `propose()` und `approve()` weisen ab, und die Agent-Tools sind nicht registriert. Bereits eingereichte Wuensche bleiben gespeichert und tauchen beim Wiedereinschalten unveraendert auf.

### Rechte

Zwei Berechtigungen, weil Ansehen und Entscheiden verschiedene Aufgaben sind:

| Recht | Erlaubt |
|---|---|
| `ai_changes[]` | Zugang zum Menuepunkt und zum Eingang (nur lesen) |
| `ai_changes[approve]` | Freigeben und ablehnen |

Ein Redakteur ohne `ai_changes[approve]` sieht die Liste, aber keine Entscheidungsknoepfe. Zusaetzlich prueft jeder Handler mit `canApprove()`, ob der freigebende User das konkrete Ziel ueberhaupt bearbeiten duerfte — beide Ebenen muessen passen.

`ai_changes[approve]` genuegt allein: `rex_be_page::checkPermission()` verknuepft die Anforderung einer Seite mit der jedes Elternteils per UND, also wuerde ein Recht ohne `ai_changes[]` an der Top-Level-Seite scheitern und den Eingang nie erreichen. `ChangeService::preparePages()` hebt die Anforderung fuer solche User auf. Wichtig, weil das allgemeine Recht und die Option in **verschiedenen Bloecken** der Rechtemaske stehen — das fehlende zweite Haekchen ist unsichtbar, nicht bloss muehsam.

### Einreichen: nur ueber die REST-API

Ein Backend-Formular zum Anlegen von Hand gab es bis 1.0.0-beta3, es ist entfernt. Es war der zweite Einreichungsweg und haette dauerhaft mit den REST-Routen Schritt halten muessen; zwei Pfade, die auseinanderlaufen, sind der Weg zu einem Formular, das annimmt was die API ablehnt.

Damit ist das **`api`-AddOn Voraussetzung** fuer das Einreichen. Fehlt es, weist die Einstellungsseite darauf hin: die Funktion liesse sich einschalten und wuerde dann nie etwas empfangen, was sich vom leeren Eingang aus schlecht diagnostizieren laesst.

Sieben Routen, jede mit eigenem Scope — die Routen werden von `ai_platform` beim `api`-AddOn angemeldet, dort ist nichts geaendert:

| Methode | Pfad | Scope-Endung | Zweck |
|---|---|---|---|
| `GET` | `/api/ai_platform/changes/describe` | `read` | Ohne Parameter: welche Typen, Operationen und Feldnamen es **hier** gibt. Mit `type` und `target`: Ist-Zustand dieses Ziels |
| `GET` | `/api/ai_platform/changes[/{id}]` | `requests` | Eigene Wuensche — ohne ID die Liste, mit ID Status, Situationsbefund und Begruendung einer Ablehnung |
| `POST` | `/api/ai_platform/changes` | `propose` | Einreichen, einzeln oder als Batch |
| `POST` | `/api/ai_platform/changes/uploads` | `upload` | Eine Datei für den Medienpool zwischenspeichern und ihren Namen reservieren |
| `POST` | `/api/ai_platform/changes/deletions` | `propose_delete` | Loeschvorschlaege — eigener Scope, weil `BearerAuth` pro Route greift |
| `POST` | `/api/ai_platform/changes/approvals` | `approve` | Eigene Wuensche selbst freigeben |
| `POST` | `/api/ai_platform/changes/withdrawals` | `withdraw` | Eigene offene Wuensche zurueckziehen |

Die Scopes erscheinen automatisch in der Token-Verwaltung des `api`-AddOns, und die OpenAPI-Doku entsteht dort ebenfalls von selbst.

**Warum es ueberhaupt mehrere Routen sind.** Das `api`-AddOn autorisiert pro Route: `BearerAuth` prueft `in_array($parameters['_route'], $token->getScopes())`. **Eine Route ist ein Scope**, feiner geht es nicht, und der Scope steckt nicht im Body. Zwei Routen zusammenzulegen heisst also immer, zwei Rechte zusammenzulegen — richtig dort, wo es dasselbe Recht war, falsch dort, wo die Trennung der Sinn der Sache ist. Deshalb bleibt `/deletions` getrennt und `operation: "delete"` auf der Propose-Route wird mit Verweis abgewiesen.

Es waren einmal acht Routen. Zwei Paare waren je ein Recht in zwei Scopes und sind zusammengefasst:

| Frueher | Jetzt | Grund |
|---|---|---|
| `/types` + `/current` | `/describe` | Beide beantworten „was ist da" — einmal allgemein, einmal fuer ein Ziel. Beide lesend, beide aendern nichts. |
| `/` (Liste) + `/{id}` | `/[{id}]` | Beide lesend, beide streng auf den `source_key` des aufrufenden Tokens gefiltert. Es gibt keinen Fall, in dem man das eine erlauben und das andere verweigern will. |

Damit bleiben **vier Rechtestufen** — schauen, vorschlagen, Bytes anbieten, entscheiden. Alte Pfade antworten nicht mehr: ein Alias, der noch antwortet, ist ein Pfad, den jemand weiter benutzt.

`/uploads` ist die vierte Stufe und wirklich getrennt: Bytes annehmen ist nicht dasselbe Recht wie einen Feldwert vorschlagen, und ein Token, das eine Überschrift vorschlagen darf, hat nichts damit zu tun, eine Platte zu füllen.

**Ein Agent braucht selten alle.** `propose` allein genuegt, wenn die Feldnamen bekannt sind — `ChangeService::propose()` liest den Ausgangszustand selbst und bildet daraus den `base_hash`, es muss also nichts vorher abgerufen werden. Praktisch sind `read` + `propose` das Minimum; `requests` kommt dazu, sobald ein Agent nachfassen soll, `approve` nur, wenn er unbeaufsichtigt schreiben darf.

**Die Quelle bestimmt das Token, nicht der Request.** `source_key` ist `api-token:<id>`, egal was im Body steht. Daran haengen der Filter im Eingang und spaeter die Annahmequote; ein frei waehlbarer Quellname wuerde beides wertlos machen.

**Antwortcodes bedeuten etwas.** `201` alle angenommen, `207` teils — dann steht in jedem Element `ok` und bei Fehlern `error` samt `index` —, `422` keins. Ein `207` als Erfolg zu lesen ist der Fehler, den diese Trennung verhindert.

### Selbst freigeben und zurueckziehen

Ein Token mit dem Scope `ai_platform/changes/approve` darf **eigene** Wuensche ohne Redakteur freigeben. Das loest einen konkreten Fall: eine Kategorie muss existieren, bevor Vorschlaege mit `parent_id` oder `article_id` darauf zeigen koennen. Ohne Selbstfreigabe braucht eine Rubrik mit Unterrubriken und Inhalten drei Runden mit menschlicher Freigabe dazwischen.

Die Vergabe des Scopes ist die Entscheidung — es gibt keinen zweiten Schalter. Zwei Regeln bleiben und sind nicht abschaltbar: es gehen nur eigene Vorschlaege, und ein blockierter Zustand (Ziel weg, Verweis kaputt, Ziel geaendert) bleibt blockiert; `force` existiert dort nicht.

Jede so entschiedene Änderung wird mit `reviewed_via = api` gespeichert und im Eingang als **„ohne Sichtung freigegeben"** gekennzeichnet. Das ist die eigentliche Kontrolle: nicht den Schreibvorgang verhindern, sondern ihn im Nachhinein unmoeglich zu uebersehen machen.

`POST /withdrawals` nimmt einen eigenen offenen Wunsch zurueck — Status `withdrawn`, bewusst nicht `rejected`: „ein Redakteur hat Nein gesagt" und „der Einreicher hat seinen Fehler bemerkt" sind verschiedene Tatsachen, und nur die erste sagt etwas ueber die Qualitaet des Vorschlags. Wer einen Fehlversuch selbst wegraeumt, macht aus seinem Fehler nicht die Arbeit eines anderen.

### Anleitung fuer Agenten

Der Tab *KI Platform > Doku > KI Änderungen* rendert dieselbe Datei, die KI-Agenten als Skill lesen: `.claude/skills/ai-platform-changes/SKILL.md`. Eine Quelle fuer beide Zielgruppen — zwei Kopien wuerden auseinanderlaufen, und die Fassung, nach der ein Agent handelt, ist die, die stimmen muss.
### Was geändert werden kann

| Typ | Ziel | Werte |
|---|---|---|
| `slice` | Artikelinhalt, per Slice-ID oder als neuer Slice | `value1`–`value20`, `media1`–`media10`, `medialist`, `link`, `linklist` |
| `article` | Artikel | `name`, `priority`, `template_id`, `status` |
| `category` | Kategorie | `catname`, `catpriority`, `status` |
| `meta` | Metainfo von Artikel, Kategorie, Medium oder Sprache | alle definierten `art_*` / `cat_*` / `med_*` / `clang_*`-Felder |
| `media` | Medienpool-Datei | `title`, `category_id` |
| `yform` | YForm-Datensatz | alle Value-Felder der Tabelle |

Die Feldlisten sind nicht erfunden: sie entsprechen genau dem, was `rex_article_service`, `rex_category_service`, `rex_content_service`, `rex_media_service` und `rex_yform_manager_dataset` tatsächlich schreiben. Alt-Texte und Copyright sind Metainfo-Felder (`med_*`) und laufen deshalb über `meta`, nicht über `media`.

### Einreichen aus PHP

```php
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\Payload\SlicePayload;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\Target\SliceTarget;

$service = ChangeService::getInstance();

// Ist-Zustand lesen, um sinnvoll formulieren zu koennen
$current = $service->read(SliceTarget::existing(sliceId: 345, articleId: 12));

$requestId = $service->propose(
    target:  SliceTarget::existing(sliceId: 345, articleId: 12),
    payload: (new SlicePayload())
                ->value(1, 'Neue Überschrift')
                ->media(1, 'berg.jpg')
                ->linkList(1, [12, 18]),
    reason:  'Überschrift kollidierte mit Artikel 12 (SEO).',
    source:  Source::php('mein_addon', 'SEO-Optimierung'),
    changesetKey: 'seo-lauf-2026-08',
);
```

Ziel und Werte sind eigene Klassen, nicht Arrays. Das ist Absicht:

* **Die Klassenwahl legt die Operation fest.** `SliceTarget::existing()` ergibt ein Update, `createIn()` ein Create, `forDeletion()` ein Delete. „Etwas anlegen, das schon existiert" laesst sich damit nicht formulieren.
* **Die Setter pruefen sofort.** `->value(21, …)` scheitert, weil `rex_article_slice` zwanzig Value-Spalten hat. `->mediaList(1, [...])` serialisiert selbst, sodass niemand das Speicherformat falsch treffen kann.
* **Nur gesetzte Felder sind Teil des Wunsches.** `->value(7, '')` leert den Slot, gar kein Aufruf laesst ihn unberührt. Die beiden Faelle bleiben unterscheidbar.

Fuer Loeschungen gibt es `proposeDelete()`, das ohne Werte auskommt.

### Einreichen aus einem Agent

Ein Agent, der ueber `Service::createAgent()` in REDAXO laeuft, bekommt drei Tools: `redaxo_describe_change_types`, `redaxo_read_current` und `redaxo_propose_change`. Sie kommen ueber `AI_PLATFORM_AGENT_TOOLS` und erscheinen **nicht** im MCP-`tools/list` — MCP ist die Flaeche fuer projekteigene Tools, und Änderungswuensche werden dort nicht unaufgefordert veroeffentlicht.

**MCP traegt Kundeninhalte und projektgewaehlte Tools, keine CMS-Interna.** Änderungswuensche, AddOn-Konfiguration, Benutzerverwaltung, Cache, Module und Templates gehoeren nicht dorthin: ein Client, der sich mit dem MCP-Server eines Kunden verbindet, soll in `tools/list` nicht die Steuerung des CMS finden. Wer es fuer die eigene Installation dennoch will, registriert ein eigenes Tool, das `ChangeService` aufruft — das ist die Entscheidung des Projekts, nicht die Voreinstellung des AddOns.

Der Kanal fuer einen Agenten von aussen ist die REST-API (siehe oben). **Die Authentifizierung liegt beim jeweiligen Adapter**, nicht im Kern: die REST-Routen pruefen Token und Scope, PHP-Code im REDAXO-Prozess gilt als vertrauenswuerdig. Der Kern garantiert nur, dass Einreichen nichts schreibt.

### Freigabe

Der Redakteur sieht pro Wunsch Typ, Ziel, Herkunft, Begruendung und einen Feld-Diff (Vorher/Vorschlag). Er kann einzeln freigeben, eine Auswahl, ein ganzes Paket oder alle offenen im aktuellen Filter.

**Rechtepruefung.** Jeder Handler prueft mit `canApprove()`, ob der freigebende Backend-User das Ziel ueberhaupt bearbeiten duerfte — Struktur-Kategorierecht, Modulrecht, Sprachrecht, Medienkategorie, YForms Tabellen-Autorisierung. Ohne das waere die Freigabe ein Weg, an den eigenen Rechten vorbeizuschreiben.

Kommt ein neuer Wunsch fuer dasselbe Ziel, werden aeltere offene Wuensche dafuer automatisch als *ueberholt* markiert — sonst koennte der Redakteur beide freigeben und der aeltere den neueren zuruecksetzen.

**Anlegen ist davon ausgenommen**, und das ist wichtig: ein Create-Ziel benennt einen *Ort*, kein Ding. Jede neue Kategorie unter Parent 40 hat dasselbe Ziel `{parent_id: 40, clang_id: 1}`. Wuerde das Ueberholen darauf greifen, ueberlebte von sechs vorgeschlagenen Unterkategorien genau eine — die anderen verschwaenden stillschweigend. Zwei Wuensche, an einem Ort etwas anzulegen, sind zwei Wuensche; zwei Wuensche, ein Feld zu aendern, sind eine Korrektur.

### Wenn sich zwischenzeitlich etwas geaendert hat

Ein Wunsch liegt Stunden oder Tage im Eingang, und in der Zeit passiert etwas. Vier Faelle werden unterschieden, weil sie unterschiedliche Antworten brauchen. Der Zustand steht als Spalte in der Liste, damit man nicht jeden Eintrag oeffnen muss.

| Zustand | Was passiert ist | Konsequenz |
|---|---|---|
| **Ziel weg** | Der Artikel, Slice, das Medium oder der Datensatz wurde geloescht | Freigabe unmoeglich, Wunsch wird *verfallen* |
| **Verweis kaputt** | Der Vorschlag zeigt auf etwas, das es nicht mehr gibt — Medium, Link-Ziel, Modul, Template, verknuepfter Datensatz | Freigabe gesperrt, **nicht** uebersteuerbar |
| **Ziel geaendert** | Die Felder des Ziels weichen vom Stand bei Einreichung ab | Freigabe gesperrt (je nach Einstellung), bewusst uebersteuerbar |
| **Umfeld geaendert** | Das Ziel selbst ist unberuehrt, seine Umgebung nicht — der Artikel hat inzwischen Slices, die Kategorie neue Kinder | Nur Warnung |

Die Unterscheidung zwischen den letzten beiden ist der Punkt, an dem es interessant wird:

**Ziel geaendert** vergleicht einen Fingerprint der Zielfelder. Der Diff wird dann dreispaltig — Basis / Vorschlag / aktuell — und benennt die Felder, die sich bewegt haben. Uebersteuern ist erlaubt: der Redakteur sieht beide Staende und entscheidet.

**Verweis kaputt** ist nicht uebersteuerbar, und zwar bewusst. Wenn das Bild aus `media1` geloescht wurde, gibt es keine Variante von „trotzdem anwenden", die korrekte Daten erzeugt — der Verweis zeigt danach ins Leere, sichtbar auf der Website und unsichtbar im Backend. Ein solcher Wunsch wird schon beim Einreichen abgewiesen, wenn der Verweis dann bereits kaputt ist.

**Umfeld geaendert** ist die einzige Pruefung, die ein *Anlegen* ueberhaupt hat: ein Create hat kein Ziel, dessen Felder man vergleichen koennte. Deshalb wird zusaetzlich die Umgebung festgehalten — bei einem neuen Slice die IDs der Slices, die im Ziel-ctype schon liegen. Ein eine Woche alter Vorschlag „neuer Slice an Position 1" landet sonst stillschweigend vor Inhalten, die es bei der Einreichung noch nicht gab, und niemand merkt es.

Zwei REDAXO-Eigenheiten sind dabei zu beachten, weil sie stille Datenverluste verursachen wuerden:

* `rex_yform_manager_dataset` haelt Instanzen in einem Pool (`get()` und `getRaw()` gleichermassen), und `save()` schreibt den **gesamten** geladenen Datensatz zurueck. Aus einem warmen Cache heraus wuerde ein Wunsch also nicht nur veraltete Werte lesen, sondern beim Speichern Felder zuruecksetzen, die niemand angefasst hat. Der Snapshot wird deshalb direkt per SQL gelesen, und vor dem Schreiben wird die gepoolte Instanz verworfen.
* Prioritaeten sind relative Positionen: REDAXO nummeriert Geschwister beim Speichern lueckenlos neu. Der gespeicherte Wert kann daher vom vorgeschlagenen abweichen. Nach dem Anwenden wird das Ziel deshalb erneut gelesen und der tatsaechlich gespeicherte Wert protokolliert — der Redakteur sieht „vorgeschlagen 6, gespeichert 3" statt eines scheinbar fehlgeschlagenen Schreibvorgangs.

### Pakete

Wuensche mit demselben `changesetKey` bilden ein Paket und lassen sich gemeinsam freigeben. Der Schluessel ist frei waehlbar und gilt pro Quelle, sodass zwei Quellen denselben Namen benutzen koennen, ohne sich zu treffen.

Ein Paket wird **nicht** als eine Transaktion angewendet, sondern Wunsch fuer Wunsch mit eigenem Endstatus. Grund: die REDAXO-Services schreiben Dateicaches und feuern Extension Points, die Mails senden oder yrewrite-Caches leeren — ein DB-Rollback macht das nicht rueckgaengig und hinterlaesst einen inkonsistenteren Zustand als ein Teilerfolg. Am Ende steht deshalb „7 uebernommen, 1 fehlgeschlagen", und der Cache betroffener Artikel wird gesammelt einmal neu aufgebaut statt pro Slice.

### Slice-Felder benennen

REDAXO-Module sind Input-/Output-PHP; was `value7` bedeutet, steht nirgends maschinenlesbar. Ohne Zuordnung sieht der Redakteur im Diff `value7` — brauchbar, weil nur geaenderte Slots gezeigt werden und der alte Wert daneben steht, aber nicht schoen.

Wer es genauer will, hinterlegt eine Zuordnung:

```php
rex_extension::register('AI_PLATFORM_MODULE_FIELDS', function (rex_extension_point $ep) {
    $map = $ep->getSubject();
    $map[12] = [
        'headline' => ['slot' => 'value1', 'label' => 'Überschrift'],
        'text'     => ['slot' => 'value2', 'label' => 'Text'],
        'image'    => ['slot' => 'media1', 'label' => 'Bild'],
    ];
    return $map;
});
```

Damit funktioniert `SlicePayload::forModule(12)->field('headline', '…')`, und der Diff zeigt „Überschrift" statt `value1`.

### Einstellungen

Alles liegt auf **einer** Seite: *KI Platform > Einstellungen*, zusammen mit den Standardprofilen und nicht im redaktionellen Menuepunkt. Ein Redakteur, der Wuensche entscheidet, brauchte diese Seite nie; ein Admin suchte sie vorher an zwei Stellen.

Zuerst der Master-Schalter — er entscheidet, ob die Funktion ueberhaupt existiert, und steuert auch den Menuepunkt. **Die Detaileinstellungen sind nur sichtbar, wenn er aktiv ist**, denn eine Einstellung fuer etwas Abgeschaltetes ist keine Information, sondern eine Einladung, etwas zu konfigurieren, das nie laeuft. Das Umschalten wirkt sofort, ohne Speichern.

Danach sind es zwei:

| Einstellung | Bedeutung |
|---|---|
| Verhalten bei geaendertem Ziel | `block` (Standard) oder `warn`, wenn sich das Ziel seit der Einreichung veraendert hat |
| Erlaubte YForm-Tabellen | Schalter „alle erlauben" (**Standard aus**) plus Liste |

Dazu zwei reine Lesetabellen: die registrierten Handler und die Menge der Wuensche je Status, jede Zeile verlinkt in den Eingang mit gesetztem Filter.

**Bei YForm ist die geschlossene Tuer der Standard**, und das ist die einzige Liste, die es noch gibt. Ein YForm-Wunsch kann jede Tabelle der Datenbank benennen; sonst wuerde eine frische Installation einen Wunsch gegen `rex_ycom_user` annehmen. Die Liste bleibt beim Umschalten erhalten, ein Ausschalten holt die vorige Auswahl zurueck.

#### Was frueher konfigurierbar war

| Entfallen | Grund |
|---|---|
| Maximale Datenmenge je Wunsch | Ein grosser Payload ist ein langer Artikel. Die Grenze verhinderte nur, dass der Vorschlag ueberhaupt gemacht wurde. |
| Aufbewahrung (Tage) | Eine Zahl im Formular setzte nichts durch — jemand musste den Knopf druecken. Jetzt ein Cronjob, siehe unten. |
| Eintraege pro Durchgang | Was ein Batch anrichtet, entscheidet sich an seinen Schreibvorgaengen, nicht an ihrer Anzahl. |
| Offene Wuensche pro Quelle | Ohne Entscheidung wird nichts geschrieben; eine Quelle in einer Schleife erzeugte Zeilen und keinen Schaden. |
| Erlaubte Module | Ersetzt durch eine Pruefung, die nicht vergessen werden kann — siehe unten. |

**Die Modul-Liste ist der interessante Fall.** Sie war fuer genau eine Sache da: ein REDAXO-Modul, dessen Output `REX_VALUE[… output=php]` enthaelt, fuehrt den Slice-Wert **als PHP aus**. Ein Vorschlag dafuer ist Code, nicht Inhalt — und mit Freigabe-Scope Code, den niemand liest, bevor er laeuft. Fast jede Installation hat so ein Modul liegen.

Eine Whitelist ist dafuer das falsche Werkzeug: sie muss gepflegt werden, sie steht standardmaessig offen damit das AddOn benutzbar bleibt, und der eine Eintrag, auf den es ankommt, ist der, den man vergisst. `ChangeService::moduleExecutesPhp()` liest stattdessen den Output des Moduls selbst — automatisch, nicht vergessbar, nichts zu konfigurieren. Es gilt fuer alle, weil es eine Eigenschaft des Moduls ist und kein Recht. Wer so ein Modul trotzdem beschreibbar will, ersetzt den Slice-Handler ueber `AI_PLATFORM_CHANGE_HANDLERS` — ein bewusster Eingriff im Code statt eines Haekchens.

### Bilder und Dateien anbieten

Ein Agent kann Dateien für den Medienpool **vorschlagen**. Das ist die einzige Route, die Bytes annimmt, und der einzige Typ, dessen Payload auf etwas außerhalb der Datenbank zeigt.

**Zwei Schritte, ein Handle:**

```bash
# 1) Datei zwischenspeichern — nichts landet im Medienpool
curl -X POST https://example.org/api/ai_platform/changes/uploads \
  -H 'Authorization: Bearer <token>' \
  -F 'file=@bulli.jpg'
# → {"data":{"upload":"up_7f3a…","filename":"bulli.jpg","mime":"image/jpeg",
#            "bytes":482113,"width":2400,"height":1600,"expires_at":"…"},
#    "meta":{"staged":true,"in_media_pool":false,"next":"POST … "}}

# 2) Den Wunsch einreichen, der auf das Handle zeigt
curl -X POST https://example.org/api/ai_platform/changes \
  -H 'Authorization: Bearer <token>' -H 'Content-Type: application/json' \
  -d '{"type":"media","operation":"create",
       "target":{"category_id":3,"filename":"bulli.jpg"},
       "fields":{"upload":"up_7f3a…","title":"Bulli am Strand"},
       "reason":"Beitragsbild fuer den neuen Artikel."}'
```

Alternativ zu `multipart/form-data` nimmt die Route auch einen rohen Body plus `?filename=` — fuer Clients, die nur Bytes senden koennen.

**Die Datei wartet ausserhalb des Medienpools**, unter `redaxo/data/addons/ai_platform/pending/`, das der Webserver per `.htaccess` sperrt. Es gibt keine oeffentliche URL dafuer, und das ist Absicht: sonst waere das Verzeichnis ein offener Dateispeicher. Der Redakteur sieht die Datei ueber einen Backend-Endpunkt, der das Recht `ai_changes[]` prueft — Bilder eingebettet und per CSS herunterskaliert, alles andere (PDF, Office) als Link.

**Der Dateiname wird beim Zwischenspeichern reserviert.** Ohne Reservierung wuerde `rex_mediapool::filename()` eine Kollision erst bei der Freigabe zu `bulli_2.jpg` machen — und einen Namen, den die einreichende Seite nicht kennt, kann sie auch nicht in einem Slice-Wunsch verwenden, denn `media1`-Slots werden **bei der Einreichung** gegen `rex_media::get()` geprueft. Ist der Name belegt, kommt eine Absage statt einer stillen Umbenennung.

**Geprueft wird zweimal**, mit denselben Regeln, die der Medienpool selbst anwendet: erlaubte Endung (inklusive Doppelendungen wie `x.php.jpg`) und echter MIME-Typ gegen den Namen. Beim Zwischenspeichern, damit der Aufrufer es sofort erfaehrt, und bei der Freigabe, weil das die Pruefung ist, die den Medienpool tatsaechlich schuetzt. Obergrenze 32 MB.

**SVG ist erlaubt**, wie im Medienpool. In der Backend-Vorschau steckt es in einem `<img>`-Tag, wo Skripte in einem SVG nicht ausgefuehrt werden — das ist die Semantik des Tags, keine Vorsichtsmassnahme. Nach der Freigabe liefert das Frontend die Datei aus `/media/` aus, wo ein Backend-CSP nicht greift. Der Kanal fuegt keine Faehigkeit hinzu, die ein Admin ueber den Medienpool nicht schon hat; neu ist nur, dass eine Maschine sie *vorschlagen* darf und ein Mensch entscheidet.

**Reihenfolge: Bild zuerst freigeben, dann der Slice.** Ein Slice-Wunsch mit `media1` wird **beim Einreichen** gegen `rex_media::get()` geprueft, nicht erst bei der Freigabe — beides in einen Batch zu packen hilft also nicht: das Bild geht durch, der Slice wird abgewiesen. Der verlaessliche Ablauf ist zweistufig: Bild vorschlagen, freigeben lassen (oder mit `approve`-Scope selbst freigeben), dann den Slice mit dem reservierten Namen einreichen. Den Namen kennt die einreichende Seite bereits aus Schritt 1 — genau dafuer gibt es die Reservierung.

Die Fehlermeldung unterscheidet die beiden Faelle: *„ist noch nicht im Medienpool — sie wartet als eigener Änderungswunsch"* heisst Reihenfolge, *„existiert nicht mehr im Medienpool"* heisst falscher Name oder geloescht. Das war vorher eine Meldung fuer beides, und die falsche fuer den haeufigeren Fall.

**Struktur verhaelt sich genauso.** Ein Artikel-Create nennt seine Kategorie ueber `category_id`, und `ArticleHandler::checkReferences()` prueft beim Einreichen, ob sie existiert — eine Kategorie und einen Artikel darin im selben Changeset einzureichen scheitert also aus zwei Gruenden: die Pruefung greift sofort, und die ID der neuen Kategorie ist noch nicht bekannt. Jede Ebene braucht eine eigene Runde: anlegen, freigeben, die ID aus `created` der Freigabe-Antwort lesen, weiter.

Ein **Changeset** bleibt nuetzlich: es fasst zusammen, was fachlich zusammengehoert, gibt dem Redakteur einen Sammel-Freigabeknopf, und `approveChangeset()` arbeitet in Einreichungsreihenfolge ab (`findByChangeset()` sortiert nach ID) — Zusage, nicht Zufall. Was es nicht kann, ist eine Abhaengigkeit aufloesen, die schon beim Einreichen geprueft wird, und das sind alle: Medienverweise, Linkziele, Zielkategorien, Module, Templates. Eine Transaktion ist es ebenfalls nicht und kann es nicht sein: die REDAXO-Services schreiben Datei-Caches und feuern Extension Points, die ein Rollback nicht zuruecknehmen wuerde.

**Eine bestehende Datei ersetzen ist weiterhin nicht moeglich.** Ein Create legt etwas an, das es nicht gab; ein Replace veraendert still, was jeder Artikel zeigt, der dieses Bild schon einbindet — und der Redakteur saehe einen Dateinamen ohne Moeglichkeit, die Reichweite zu beurteilen. Das braucht seinen eigenen Diff, bevor es einen Endpunkt verdient.

### Aufbewahrung: der Cronjob

Geloescht wird nie. Der Cronjob **„KI Änderungen: Datenmenge alter Wuensche reduzieren"** (verfuegbar, wenn das `cronjob`-AddOn installiert ist) leert bei lange entschiedenen Wuenschen `payload`, `payload_edited`, `snapshot_before` und `context_snapshot` — diese vier Spalten sind praktisch das gesamte Volumen. Der Zeitraum wird im Cronjob eingestellt, dort wo auch der Zeitplan steht.

Derselbe Cronjob raeumt die **zwischengespeicherten Dateien** auf, die andere Art Volumen und die einzige, die in Megabyte zaehlt. Zwei Durchgaenge mit unterschiedlichen Fristen: Dateien von Wuenschen, die vor dem Stichtag entschieden wurden, und Uploads, auf die nie ein Wunsch gezeigt hat, nach Ablauf ihrer 24-Stunden-Reservierung. Ein offener Wunsch behaelt seine Datei, egal wie alt er ist — ein Vorschlag, dessen Bild geloescht wurde, ist keine Ersparnis, sondern ein kaputter Vorschlag.

Die Zeile selbst bleibt dauerhaft. Wer wann was auf welchem Ziel entschieden hat, ist das Protokoll — und sobald API-Token eigene Vorschlaege freigeben koennen, ist es das Einzige, was zwischen einem automatischen Schreibvorgang und einer unerklaerlichen Änderung steht. Offene Wuensche werden unabhaengig vom Alter nie angefasst: ein offener Wunsch ohne Payload ist keine Ersparnis, sondern ein kaputter Wunsch.

Ein Vorschlag, dessen `payload` bei `GET /{id}` leer ist, war also nicht kaputt — er ist alt und wurde verkleinert.
### Eigene Änderungstypen

Ein AddOn kann einen eigenen Typ beitragen: ein Target, ein Payload und ein Handler, registriert ueber `AI_PLATFORM_CHANGE_HANDLERS`. Der Kern kennt nur das Interface, kein Kernfile muss angefasst werden.

```php
rex_extension::register('AI_PLATFORM_CHANGE_HANDLERS', function (rex_extension_point $ep) {
    $handlers = $ep->getSubject();
    $handlers['mein_typ'] = new MeinHandler();
    return $handlers;
});
```

`FriendsOfRedaxo\AiPlatform\Change\AbstractHandler` liefert Fingerprint, generischen Diff und „Rücknahme nicht unterstuetzt" als Vorgabe. Zu implementieren bleiben `readCurrent()`, `validate()`, `canApprove()` und `apply()`.

## Extension Points

| Extension Point | Beschreibung | Subject |
|---|---|---|
| `AI_PLATFORM_MCP_TOOLS` | Tools fuer den MCP-Server registrieren | `array<string, FriendsOfRedaxo\AiPlatform\Mcp\Tool>` |
| `AI_PLATFORM_AGENT_TOOLS` | Tools fuer den Agent registrieren | `array<object>` (Symfony AI Tool-Objekte) |
| `AI_PLATFORM_OAUTH_SCOPES` | Eigene Scopes fuer das Scope-Mapping ankuendigen | `array<string, string>` (scope → description) |
| `AI_PLATFORM_CHANGE_HANDLERS` | Eigene Änderungstypen registrieren | `array<string, FriendsOfRedaxo\AiPlatform\Change\HandlerInterface>` |
| `AI_PLATFORM_MODULE_FIELDS` | Feldnamen der REX_VALUE-Slots eines Moduls hinterlegen | `array<int, array<string, array{slot: string, label: string}>>` |
| `AI_PLATFORM_CHANGE_PROPOSED` | Nach dem Einreichen eines Änderungswunsches | `null`, Params: `request_id`, `type`, `operation`, `source_key` |
| `AI_PLATFORM_CHANGE_BEFORE_APPLY` | Vor dem Anwenden; ein zurueckgegebener String verhindert die Freigabe | `null`, Params: `request`, `user` |
| `AI_PLATFORM_CHANGE_APPLIED` | Nach erfolgreichem Anwenden | `null`, Params: `request_id`, `type`, `result`, `user` |

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
