# AI Platform für REDAXO 6

LLM-Profile auf [Symfony AI](https://symfony.com/doc/current/ai/index.html) und ein MCP-Server über das offizielle
[PHP-SDK](https://github.com/modelcontextprotocol/php-sdk), für REDAXO 6 (Branch `2.x`). Die Fassung für REDAXO 5 ist
`main` (1.x).

- **Profile:** ein Profil ist ein Anwendungsfall – Typ (Text, Embeddings, Bilderzeugung, Bildverständnis), Anbieter,
  Modell und die Optionen des Typs. Zehn Anbieter sind eingebaut, weitere kommen über einen Extension Point dazu.
- **Schlüssel in der Umgebung:** API-Schlüssel stehen nicht in der Datenbank, sondern in `.env.local` der Instanz.
- **PHP-API** für andere AddOns: Text, Embeddings, Bilder, Bildverständnis, Agenten mit Werkzeugen.
- **MCP-Server** unter `/api/mcp` (über das AddOn „api"): Tools sind Methoden mit `#[McpTool]` – im Projekt oder in
  einem AddOn, gefunden über die Class Discovery des Core.

## Installation

Composer-AddOn wie alle AddOns für REDAXO 6:

```bash
composer require friendsofredaxo/ai_platform:2.x-dev
php bin/console migrate
```

Symfony AI steht derzeit bei `1.0.0-BETA1`; das Projekt braucht dafür `"minimum-stability": "dev"` oder `"beta"` mit
`"prefer-stable": true`.

## Schlüssel

Ein Profil nennt nur den **Namen** der Umgebungsvariable, die den Schlüssel enthält. Ohne Eintrag gilt
`AI_PLATFORM_<ANBIETER>_API_KEY`:

```dotenv
# .env.local (nicht versioniert)
AI_PLATFORM_OPENAI_API_KEY=sk-…
AI_PLATFORM_ANTHROPIC_API_KEY=sk-ant-…
```

Mehrere Profile eines Anbieters teilen sich so den Schlüssel. Ein eigener Name (etwa `AI_PLATFORM_OPENAI_KUNDE_B`) ist
nur nötig, wenn zwei Profile verschiedene Konten nutzen.

Warum nicht in der Datenbank: Datenbank-Dumps wandern zwischen Live, Staging und lokal, und mit ihnen jeder Schlüssel,
der darin steht – in 1.x im Klartext. In der Umgebung bleibt er auf dem Server, für den er ausgegeben wurde, genau wie
`DATABASE_URL` und `MAILER_DSN`. Ollama und OpenAI-kompatible Server laufen meist ohne Schlüssel; die Variable ist dort
optional.

Ob alles da ist, zeigt die Profilliste im Backend oder auf der Konsole:

```bash
php bin/console ai:profiles          # Profile, Variable, gesetzt ja/nein (Exit 1, wenn eine fehlt)
php bin/console ai:test 3            # kleinste Anfrage über Profil 3 (ID oder Name)
php bin/console ai:test --type=embedding
php bin/console ai:ask "Fasse REDAXO in einem Satz zusammen."
```

## Profile

*AI Platform → Profile*. Je nach Typ zeigt das Formular Temperature, Max. Tokens und System-Prompt (Text,
Bildverständnis), Bildgröße und -qualität (Bilderzeugung) oder nichts weiter (Embeddings). Das Modellfeld schlägt die
Modelle aus dem Katalog von Symfony AI vor; ein Name, den der Katalog nicht kennt, lässt sich frei eintragen. Der Knopf
*Verbindung testen* schickt die kleinste Anfrage, die zeigt, dass Schlüssel und Modell funktionieren.

*AI Platform → Einstellungen* legt das Standardprofil je Typ fest und die Zeitgrenzen der Anfragen (eine lange, nicht
gestreamte Antwort sendet nichts, bis sie fertig ist).

| Anbieter | Schlüssel | Hinweis |
|---|---|---|
| OpenAI, Anthropic, Google (Gemini), Mistral, Cerebras, Scaleway, OpenRouter, Replicate | nötig | |
| Ollama | optional | Basis-URL, Vorgabe `http://localhost:11434` |
| OpenAI-kompatibel (generic) | optional | Basis-URL, z. B. LM Studio `http://localhost:1234/v1` |

## PHP-API

```php
use FriendsOfRedaxo\AiPlatform\Service;

$ai = Service::getInstance();

$text = $ai->generateText('Beschreibe REDAXO in drei Sätzen.');            // Standardprofil „Text"
$text = $ai->generateText('Übersetze: Hallo Welt', 'Du bist Übersetzer.', 2); // System-Prompt, Profil 2

$vector  = $ai->generateEmbedding('REDAXO ist ein CMS.');                  // list<float>
$vectors = $ai->generateEmbedding(['Text A', 'Text B']);                   // list<list<float>>

$alt = $ai->understandImage('Kurzer Alt-Text für dieses Bild.', Path::media('foto.jpg'));
$src = $ai->generateImage('Ein Logo für einen Fußballverein');             // URL oder Data-URI

if ($ai->hasDefaultProfile('embedding')) { … }                             // Funktion nur anbieten, wenn sie geht
```

Profiloptionen (Temperature, Max. Tokens, Bildgröße …) gehen bei jedem Aufruf mit. Lehnt ein Modell `temperature` ab
(neuere Reasoning-Modelle), wird einmal ohne den Wert wiederholt.

Direkt mit Symfony AI:

```php
$platform = $ai->getPlatform($profileId);
$result = $platform->invoke($ai->getProfile($profileId)['model'], $messages, $ai->getProfileOptions($profileId));
```

Agent mit Werkzeugen (Symfony AI Toolbox); weitere Werkzeuge liefern AddOns über den Extension Point
`AI_PLATFORM_AGENT_TOOLS`:

```php
$agent = $ai->createAgent('text', [new MeinWerkzeug()]);
echo $agent->call(new MessageBag(Message::ofUser('…')))->asText();
```

### Eigene Anbieter

Eine Symfony-AI-Bridge per Composer, dazu ein Eintrag im Extension Point `AI_PLATFORM_PROVIDERS`. Ein vorhandener
Schlüssel ersetzt den eingebauten Anbieter.

```php
Extension::register(ProviderRegistry::EXTENSION_POINT, static function (ExtensionPoint $ep): array {
    $providers = $ep->subject;
    $providers['perplexity'] = [
        'label' => 'Perplexity',
        'fields' => ['api_key'],
        'defaults' => ['text' => 'sonar-pro'],
        'catalog' => static fn () => new PerplexityCatalog(),
        'factory' => static fn (array $profile, $client) => PerplexityFactory::createPlatform($profile['api_key'], $client),
    ];

    return $providers;
});
```

`$profile['api_key']` enthält dort schon den Wert aus der Umgebung; `'api_key_optional' => true` für Anbieter, die
auch ohne laufen.

## MCP-Server

Der Server spricht Streamable HTTP und läuft als Route `/api/mcp` des AddOns
[api](https://github.com/FriendsOfREDAXO/api) (Branch `2.x`). Das AddOn bringt mit, was ein Endpunkt von außen braucht:
Tokens mit Ablaufdatum, Scopes, Routing vor yrewrite. Ein Client braucht ein Token mit dem Scope **`mcp`**
(*API → Token*). Ohne das AddOn api gibt es keinen MCP-Server; Profile und PHP-API arbeiten unverändert.

*AI Platform → MCP-Server* zeigt Adresse, Konfiguration für die Clients, alle Tools und ein Feld für Hinweise an das
Modell (MCP „instructions").

```bash
claude mcp add --transport http redaxo https://example.org/api/mcp --header "Authorization: Bearer <TOKEN>"
```

Eingebaut ist `redaxo_status`. Das AddOn search bringt `search`, `search_sources` und `search_passages` mit.

### Tools schreiben

Eine Methode mit dem Attribut des SDK, irgendwo unter `src/` des Projekts oder eines AddOns. Name, Beschreibung und
Eingabeschema leitet das SDK aus Methode, Docblock und Parametertypen ab:

```php
use Mcp\Capability\Attribute\McpTool;

final class Opening
{
    /**
     * Opening hours of a location.
     *
     * @param string $location name of the location, e.g. "Geschäftsstelle"
     */
    #[McpTool(name: 'opening_hours')]
    public static function hours(string $location): array { … }
}
```

Nach neuen Klassen `php bin/console cache:clear` (Class Discovery). Ein Tool meldet einen Fehler an das Modell mit
`throw new Mcp\Exception\ToolCallException('…')`; jede andere Exception landet im Systemlog von REDAXO, der Client
bekommt nur „Error while executing tool". Wer im Tool wissen will, mit welchem Token gefragt wird:
`McpEndpoint::getToken()`.

Tools, die erst zur Laufzeit feststehen, kommen über den Extension Point `AI_PLATFORM_MCP_TOOLS` (benannte Argumente
von `Mcp\Server\Builder::addTool()`). Objekte als Attribut-Argumente (`annotations: new ToolAnnotations(…)`) kann die
Class Discovery nicht zwischenspeichern; Hinweise wie `readOnlyHint` deshalb über den Extension Point, dort auch als
Array.

### Warum das offizielle SDK

`mcp/sdk` ist die Bibliothek, die PHP Foundation und Symfony gemeinsam pflegen; `symfony/mcp-bundle` ist dieselbe
Bibliothek mit der Verdrahtung für FrameworkBundle und Container, die es in REDAXO nicht gibt. Das SDK übernimmt
Protokoll, Sitzungen, Schema-Ableitung und Validierung, die 1.x selbst gebaut hatte. Sitzungen liegen in Dateien unter
`var/cache/addons/ai_platform/mcp-sessions`, weil jede Anfrage ein eigener PHP-Prozess ist. Einen dauerhaft offenen
SSE-Kanal per GET gibt es nicht (405, von der Spezifikation erlaubt).

## Unterschiede zu 1.x

| 1.x (REDAXO 5) | 2.x (REDAXO 6) |
|---|---|
| Schlüssel in `rex_ai_profile.api_key` | Umgebungsvariable, Spalte `api_key_env` |
| Symfony AI 0.14, `vendor/` im Repository | Symfony AI 1.0 über Composer |
| eigener MCP-Server, OAuth 2.1 mit YCom, `/mcp` | `mcp/sdk`, Tokens des AddOns api, `/api/mcp` |
| Tools als `Tool`-Objekte im Extension Point | `#[McpTool]`-Methoden, Extension Point für Sonderfälle |
| Änderungswünsche (Change Requests) | noch nicht portiert |
| `rex_api_ai_test`, Fragmente, Bootstrap | API-Funktion `ai_platform_test`, Komponenten des Core |

Die Methoden von `Service` sind gleich geblieben, ebenso `ProviderRegistry` und die Extension Points
`AI_PLATFORM_PROVIDERS` und `AI_PLATFORM_AGENT_TOOLS`.
