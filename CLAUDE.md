# CLAUDE.md

AddOn `ai_platform`, Branch `2.x` für REDAXO 6 (Composer-AddOn, `friendsofredaxo/ai_platform`). `main` ist die Fassung
für REDAXO 5 (1.x) und wird hier nicht angefasst.

- **Profile** in `rex_ai_profile`, Schlüssel nie in der Datenbank: `ProviderRegistry::createPlatform()` holt ihn aus
  der Umgebungsvariable des Profils (`api_key_env`, Vorgabe `AI_PLATFORM_<ANBIETER>_API_KEY`) und reicht ihn den
  Factories als `$profile['api_key']` weiter. Backend und Konsole zeigen nur, ob die Variable gesetzt ist.
- **Anbieter** stehen vollständig in `ProviderRegistry::all()` (Formularfelder, Vorgabemodelle, Katalog, Factory);
  `assets/profiles.js` kennt keinen Anbieter und liest `formConfig()` aus dem JSON neben dem Formular.
- **MCP:** `Mcp\McpEndpoint` registriert `/api/mcp` beim AddOn api (Scope `mcp`), `Mcp\McpServer` baut je Anfrage den
  Server des SDK (`mcp/sdk`) mit Datei-Sitzungen, `Mcp\ToolRegistry` findet `#[McpTool]`-Methoden über
  `ClassDiscovery`. Attribut-Argumente müssen sich exportieren lassen (keine Objekte).
- Prüfen gegen eine REDAXO-6-Instanz, die das AddOn als Path-Repository einbindet: `bin/console ai:profiles`,
  `ai:test`, `ai:ask`; MCP per curl (initialize → `notifications/initialized` → `tools/list` → `tools/call`, mit
  `Mcp-Session-Id` und `MCP-Protocol-Version`).
- Codestil: `../redaxo6/vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php` (Konfiguration `redaxo6()`).
