---
name: ai-platform
description: Das REDAXO-AddOn ai_platform — LLM-Profile, die PHP-Service-API für Text/Bild/Embeddings, tool-nutzende Agenten und der MCP-Server mit OAuth. Use when generating text or images from REDAXO, adding an LLM provider, building an agent with tools, exposing content over MCP, or debugging why a profile or the MCP endpoint does not work. Für Änderungswünsche (Inhalte vorschlagen statt schreiben) siehe den Skill ai-platform-changes.
---

# ai_platform: LLM-Schicht für REDAXO

Das AddOn kapselt **Symfony AI 0.6** und stellt vier Dinge bereit: Profile,
eine PHP-API, Agenten mit Tools, und einen MCP-Server. Änderungswünsche sind ein
eigener Bereich mit eigenem Skill (`ai-platform-changes`).

## Profile: eine Konfiguration pro Anwendungsfall

Ein Profil ist **ein Typ + ein Provider + ein Modell + die Optionen dieses
Typs**. Nicht ein Profil pro Provider, sondern eines pro Aufgabe — „Produkttexte
kurz", „Alt-Texte", „Bildbeschreibung ausführlich".

| Typ | Relevante Felder |
|---|---|
| `text` | `temperature`, `max_tokens`, `system_prompt` |
| `image_generation` | `image_size`, `image_quality`, `image_style` (die letzten zwei nur DALL·E) |
| `image_understanding` | `temperature`, `max_tokens`, `detail_level` |
| `embedding` | Modell |

**Die Konfiguration nach Typ ist nur im Formular gefiltert, nicht in der
Datenbank.** `assets/profiles.js` blendet ein und aus; serverseitig wird nichts
verworfen. Ein Wert, der früher zu einem anderen Typ gesetzt wurde, bleibt also
gespeichert — beim Debuggen lohnt der Blick in die Zeile, nicht nur ins Formular.

**Zehn Provider**, alle in `ProviderRegistry` (`lib/ProviderRegistry.php`):
OpenAI, Anthropic, Google, Ollama, Mistral, Cerebras, Scaleway, OpenRouter,
Replicate und „OpenAI-kompatibel" (freie Basis-URL). Welche Felder ein Profil
zeigt, steht im Eintrag des Providers — eine Base-URL haben nur Ollama und
OpenAI-kompatibel, und bei beiden ist der API-Key optional: leer heißt kein
`Authorization`-Header, gesetzt heißt Bearer-Token für einen abgesicherten
Server.

**Das Modellfeld ist eine Auswahl plus Textfeld.** Die Auswahl kommt aus dem
Modellkatalog von Symfony AI, gefiltert nach den Fähigkeiten, die der Typ
braucht — das AddOn pflegt keine Modelllisten. Der letzte Eintrag „eigener
Modellname" schaltet das Textfeld frei, und das ist keine Zierde: die Kataloge
haben Lücken. Bei Ollama fehlt `llama3.2-vision` ganz und `llava` trägt keine
`INPUT_IMAGE`-Fähigkeit, die Bildverständnis-Auswahl ist dort also leer. Ob ein
selbst eingetragener Name dann funktioniert, entscheidet der Katalog des
Providers: `FallbackModelCatalog` (OpenAI-kompatibel) nimmt jeden Namen, die
gepflegten Kataloge weisen einen unbekannten mit `ModelNotFoundException` ab —
noch vor dem ersten Request.

**`getProfile()` filtert auf `status = 1`.** Ein inaktives Profil ist für jeden
Aufrufer unsichtbar, nicht nur im Backend. Wer „Profil nicht gefunden" bekommt
und die Zeile in der Datenbank sieht, prüft zuerst den Status.

Standardprofile pro Typ stehen unter *KI Platform → Einstellungen*.
Sie werden über `rex_config` gespeichert und von den Helfern unten benutzt, wenn
kein Profil mitgegeben wird.

## Die PHP-API

```php
use FriendsOfRedaxo\AiPlatform\Service;

$service = Service::getInstance();

$text = $service->generateText('Fasse diesen Artikel in drei Sätzen zusammen: …');
$text = $service->generateText($prompt, profileId: 4);           // bestimmtes Profil

$beschreibung = $service->understandImage('Was ist zu sehen?', '/pfad/bild.jpg');
$url          = $service->generateImage('Ein Bulli am Strand, Abendlicht');
$vektor       = $service->generateEmbedding('Suchbegriff');
```

Ohne `profileId` greift das Standardprofil des jeweiligen Typs. Fehlt das, fliegt
`rex_exception('No default AI profile configured for: …')` — der häufigste
Einstiegsfehler.

**Es gibt keine Fremdschlüssel auf Profile.** Wird ein Profil gelöscht, auf das
ein `default_*_profile` zeigt, bleibt die Konfiguration auf die verschwundene ID
gerichtet und der nächste Aufruf wirft. Nach dem Löschen also die
Standardprofile prüfen.

### Agenten mit Tools

```php
$agent = $service->createAgent('text');
$antwort = $agent->call('Wie viele Artikel hat die Kategorie Aktuelles?');
```

Die Tools kommen über `AI_PLATFORM_AGENT_TOOLS`. **Die Subjekte dort sind
Symfony-AI-Tool-Objekte**, nicht die `Mcp\Tool`-Objekte des MCP-Servers — die
zwei Systeme sind absichtlich getrennt, weil ihre Objektformen sich
unterscheiden. Wer ein Tool an beiden Stellen will, registriert es zweimal.

```php
rex_extension::register('AI_PLATFORM_AGENT_TOOLS', function (rex_extension_point $ep) {
    $tools = $ep->getSubject();
    $tools[] = new MeinTool();          // Klasse mit #[AsTool(...)]
    return $tools;
});
```

Drei Tools für Änderungswünsche sind bereits registriert, wenn die Funktion aktiv
ist — siehe Skill `ai-platform-changes`.

## Der MCP-Server

Läuft unter `POST /mcp`, JSON-RPC 2.0, Protokollversion `2025-03-26`. Daneben
`/.well-known/oauth-*`, `/oauth/authorize`, `/oauth/token`, `/oauth/register`
(RFC-7591 Dynamic Client Registration).

### Was dort hingehört — und was nicht

**Der MCP-Endpunkt trägt die Inhalte eines Projekts und die Tools, die dieses
Projekt anbieten will. Er ist keine Fernsteuerung für REDAXO-Interna.** Das ist
eine Produktentscheidung, keine technische Grenze, und sie gilt auch dann, wenn
eine interne Funktion trivial zu exponieren wäre:

* **Gehört auf `/mcp`:** Artikel, Medien, Datensätze, Suche des Kunden — was ein
  fremder Assistent *über diese Website* sehen soll.
* **Gehört nicht dorthin:** Änderungswünsche, AddOn-Konfiguration,
  Benutzerverwaltung, Cache, Modul- und Template-Bearbeitung. Ein Client, der
  sich mit dem MCP-Server eines Kunden verbindet, soll in `tools/list` nicht die
  Steuerung des CMS finden.

Für einen Agenten von außen, der Inhalte ändern soll, ist der Kanal die
REST-API der Änderungswünsche, nicht MCP — siehe Skill `ai-platform-changes`.

### Tools registrieren

```php
rex_extension::register('AI_PLATFORM_MCP_TOOLS', function (rex_extension_point $ep) {
    $tools = $ep->getSubject();
    $tools['meine_suche'] = new FriendsOfRedaxo\AiPlatform\Mcp\Tool(
        name: 'meine_suche',
        description: 'Durchsucht die Produktdatenbank.',
        inputSchema: [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string', 'description' => 'Suchbegriff']],
            'required' => ['q'],
        ],
        handler: function (array $args, FriendsOfRedaxo\AiPlatform\Mcp\Context $ctx): string {
            return sucheProdukte((string) $args['q']);
        },
        public: false,                       // ohne Auth nicht sichtbar
        requiredScopes: ['produkte/lesen'],
    );
    return $tools;
});
```

* `public: true` → ohne Authentifizierung aufrufbar. `redaxo_status` ist das
  eingebaute Beispiel.
* Ohne `public` braucht der Aufrufer ein gültiges OAuth-Token. `tools/list`
  filtert mit `isCallableBy()`, ein anonymer Client sieht geschützte Tools also
  gar nicht.
* Scopes lösen über **YCom-User → YCom-Gruppen → `rex_ai_scope_mapping`** auf.
  Es gibt **keine eingebauten Scopes**; alle auswählbaren kommen von AddOns über
  `AI_PLATFORM_OAUTH_SCOPES`.

### Weiter im MCP-Skill

Alles zu Auth-Modell, OAuth-Endpunkten, Discovery hinter Proxy und den
Fallstricken mit Claude Desktop steht in **`ai-platform-mcp`** — dort in der
Tiefe, hier nur der Einstieg. Zwei Dinge, die man vorher wissen sollte:

* **`mcp_require_auth = 1`** lässt den Server *jede* Methode für anonyme
  Aufrufer mit 401 beantworten, auch `initialize` und `tools/list`. Nötig, weil
  manche Clients OAuth erst bei einem 401 starten und geschützte Tools sonst nie
  zu sehen bekommen.
* **`mcp_enabled` ist standardmäßig aus.** Eine frische Installation antwortet
  auf `/mcp` mit 503.

## Arbeiten am AddOn

> **Assets werden nicht aus `assets/` ausgeliefert.** REDAXO kopiert sie bei der
> Installation nach `assets/addons/ai_platform/`. Nach jeder Änderung an
> `assets/*.css` oder `assets/*.js`:
>
> ```bash
> echo "y" | redaxo/bin/console package:install ai_platform
> ```
>
> Das ist die häufigste Ursache für „meine Änderung wirkt nicht".
> `change-pages-test.php` vergleicht Quelle und Kopie und schlägt bei Abweichung
> fehl.
>
> `boot.php` hängt als Cache-Buster die **mtime der ausgelieferten Kopie** an, nicht
> die AddOn-Version. Die Version passt für Releases und hilft hier nicht: sie
> bleibt über Dutzende Asset-Änderungen gleich, die Reinstallation frischt die
> Datei auf der Platte auf, und der Browser liefert unter unverändertem `?v=`
> weiter die alte aus. Das sieht aus wie eine vergessene Reinstallation — genau
> diese Fehlsuche ist einmal passiert. Die mtime ändert sich genau dann, wenn sich
> die Kopie ändert.

```bash
composer dump-autoload            # nach neuen Klassen in lib/
composer install --no-dev         # vendor/ und composer.lock sind eingecheckt
```

`vendor/` und `composer.lock` liegen im Repo — FoR-Konvention, damit der
AddOn-Installer-Download vollständig ist und Endanwender auf dem Server kein
Composer brauchen. Deps ändern heißt: `composer update`, manuell testen
(Profil anlegen, Verbindungstest, ein MCP-`tools/list`), dann **Lock und vendor
zusammen** committen.

Symfony AI ist auf `^0.6` gepinnt und alpha-nah: Brüche zwischen 0.x-Minors sind
wahrscheinlich. Nach einem Update `php .claude/tests/provider-registry-test.php`
laufen lassen — der Test treibt jeden Provider der Registry durch seine Bridge
(gegen einen `MockHttpClient`, ohne Netz und ohne Schlüssel) und prüft, dass
jedes Default-Modell noch im Katalog steht.

### Einen Provider hinzufügen

**Ein Eintrag in `ProviderRegistry::all()`, sonst nichts** — plus das
`composer require` für die Bridge. Der Eintrag trägt alles zu diesem Provider:
`label`, `fields` (welche Felder das Formular zeigt), `defaults` (Vorbelegung des
Modells pro Typ), `catalog` (woher die Modellauswahl kommt) und `factory` (baut
die Symfony-AI-Platform).

**Von außerhalb des AddOns geht es ohne Fork** — ein Eintrag im Extension Point
`AI_PLATFORM_PROVIDERS`:

```php
use FriendsOfRedaxo\AiPlatform\ProviderRegistry;
use Symfony\AI\Platform\Bridge\Perplexity\ModelCatalog as PerplexityCatalog;
use Symfony\AI\Platform\Bridge\Perplexity\PlatformFactory as PerplexityFactory;

rex_extension::register(ProviderRegistry::EXTENSION_POINT, function (rex_extension_point $ep) {
    $providers = $ep->getSubject();
    $providers['perplexity'] = [
        'label' => 'Perplexity',
        'fields' => ['api_key'],                       // 'base_url', 'image_quality', 'image_style'
        'defaults' => ['text' => 'sonar-pro'],
        'catalog' => static fn () => new PerplexityCatalog(),
        'factory' => static fn (array $profile, $httpClient) => PerplexityFactory::create($profile['api_key'], $httpClient),
    ];
    return $providers;
});
```

**`assets/profiles.js` bleibt frei von Providernamen.** Feldsichtbarkeit,
Default-Modell und Modellliste kommen als JSON aus
`ProviderRegistry::formConfig()`, das `pages/profiles.php` in ein
`<script type="application/json" id="ai-provider-config">` schreibt. Dieselbe
Kenntnis lag einmal an beiden Stellen — und die JS-Kopie hielt das
API-Key-Feld bei Ollama noch lange verborgen, nachdem PHP es schon
unterstützte.

Die Definitionen werden bei **jedem** Aufruf neu gebaut. Sie statisch zu cachen
würde die Menge einfrieren, die beim ersten Zugriff existierte — ein später in
der Boot-Reihenfolge registrierter Provider wäre dann still verschwunden.

Bleibt: ggf. `rex_api_ai_test` für den Verbindungstest anpassen.

**Nicht „aufräumen":** `getProfileOptions()` verzweigt bewusst zwischen
`max_output_tokens` (OpenAIs Responses-API) und `max_tokens` (alle anderen). Das
ist kein Fehler.

**Bildgenerierungs-Profile lassen sich nicht vollständig testen.** Für OpenAI
fällt der Test auf ein GET gegen `/v1/models/<model>` zurück, um den Key zu
prüfen; für die anderen kommt der Hinweis, manuell über ein Textprofil mit
demselben Key zu testen.

### Wenn Code mitten im Ablauf aussteigt

`Mcp\Router` und `rex_api_ai_test` beenden den Request mit `exit` und umgehen
damit REDAXOs normale Response-Pipeline. Regeln dafür: immer zuerst
`rex_response::cleanOutputBuffers()`, niemals `rex_response::sendContent()`
darauf vertrauen, und daran denken, dass der Router auf **jedem**
Frontend-Request läuft — die Pfadtabelle bleibt klein.

## Tests

Reproduzierbarer Harness in `.claude/tests/`, DB-Zugang aus
`data/core/config.yml` abgeleitet:

```bash
php  .claude/tests/provider-registry-test.php      # Provider, Kataloge, Bridges (180)
node .claude/tests/model-picker-test.mjs           # Modellauswahl im Formular (19)
php  .claude/tests/oauth-storage-test.php          # OAuth-Speicherschicht
bash .claude/tests/oauth-token-endpoint-test.sh    # Token-Endpunkt über curl
bash .claude/tests/oauth-authorize-test.sh         # Login + Consent + Tausch
php  .claude/tests/change-*-test.php               # Änderungswünsche
php  .claude/tests/change-pages-test.php           # Backend-Seiten und Rechte
bash .claude/tests/api-changes-test.sh             # REST-Routen über HTTP
```

`model-picker-test.mjs` läuft mit blankem `node` gegen ein handgeschriebenes
Minimal-DOM, ohne npm. Sein Provider-Payload ist absichtlich eine Fixture: das
Verhalten der Auswahl darf nicht deshalb fehlschlagen, weil Symfony AI ein
Modell ergänzt hat. Dass der echte Payload diese Form hat, prüft der PHP-Test.

`bootstrap.php` erledigt drei Dinge, die ein naiver CLI-Bootstrap falsch macht:
`packages.cache` löschen (sonst bleibt eine neu deklarierte Seite unsichtbar),
`enlist()` pro Paket aufrufen (sonst gibt `rex_i18n::msg()` überall
`[translate:key]` zurück), und `rex_autoload::reload()` erzwingen (sonst sind
neue Klassen nicht ladbar). Vor Arbeiten an `lib/Mcp/`, `lib/OAuth/` oder
`lib/Change/` einmal alles laufen lassen, um die Ausgangslage festzuhalten.
