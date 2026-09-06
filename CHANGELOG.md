# Changelog

Alle nennenswerten Änderungen an diesem AddOn.

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [1.0.0] – 2026-09-06

### Hinzugefügt

- **Änderungswünsche**: Agenten und AddOns schlagen Inhaltsänderungen vor, ein Redakteur gibt sie frei — für Slices, Artikel, Kategorien, Metainfo, Medien und YForm-Datensätze.
- Sieben REST-Routen für Änderungswünsche, angemeldet beim `api`-AddOn, jede mit eigenem Scope: einsehen, einreichen, Datei hochladen, löschen lassen, freigeben, zurückziehen.
- Drei Agent-Tools über `AI_PLATFORM_AGENT_TOOLS` für Änderungswünsche aus einem Agenten heraus.
- Medien-Upload für Änderungswünsche: Datei außerhalb des Medienpools zwischenspeichern, Name reservieren, erst bei Freigabe einspielen.
- Cronjob, der die Nutzdaten entschiedener Änderungswünsche nach einer Frist leert; die Zeile selbst bleibt als Nachweis.
- Eingang für Änderungswünsche als eigener Menüpunkt neben Struktur und Medienpool, mit zwei Rechten (`ai_changes[]`, `ai_changes[approve]`).
- **Sechs neue Provider**: Mistral, Cerebras, Scaleway, OpenRouter, Replicate und OpenAI-kompatibel (freie Basis-URL für Open WebUI, LiteLLM, vLLM, LM Studio und Ähnliches).
- `ProviderRegistry` mit Extension Point `AI_PLATFORM_PROVIDERS`: ein AddOn ergänzt einen eigenen LLM-Provider ohne Fork.
- Modellauswahl im Profilformular aus dem Modellkatalog von Symfony AI, gefiltert nach Profiltyp, mit Suchfeld und dem Eintrag „eigener Modellname" für alles, was der Katalog nicht führt.
- Migration bestehender Bildprofile beim Update (`update.php`), siehe unten.
- Testsatz: Provider und Bridges, Modellauswahl, Agent, Änderungswünsche, REST-Routen.

### Geändert

- **Symfony AI von 0.6 auf 0.13.** Damit aktuelle Modellkataloge: Claude Opus 5 und Sonnet 5, Gemini 3, GPT-5. Vorher endete die Auswahl bei Claude Sonnet 4.5, und ein neuerer Name ließ sich auch von Hand nicht eintragen.
- **Bildgenerierung bei OpenAI läuft über die `gpt-image`-Modelle**; `dall-e-2` und `dall-e-3` sind aus dem Katalog von Symfony AI entfernt. Bestehende Profile werden beim Update umgeschrieben: Modell auf `gpt-image-1`, Qualität `hd` → `high` und `standard` → `medium`, die alten Bildgrößen auf das nächstliegende neue Format.
- `generateImage()` liefert eine URL oder eine Data-URI, je nachdem, was der Provider zurückgibt — die `gpt-image`-Modelle senden die Bilddaten base64-kodiert und nie eine URL. Beides passt in ein `src`-Attribut.
- **Ollama fragt den Server nach den Modellen**, statt eine feste Liste mitzubringen. Damit ist jedes installierte Modell nutzbar, auch `llama3.2-vision`, das vorher nicht erreichbar war. Im Formular steht dafür ein Textfeld statt einer Auswahl.
- Bei Ollama geht ein eingetragener API-Key als Bearer-Token mit — für einen per Reverse Proxy abgesicherten Server. Leer bleibt er weiterhin optional.
- Providerspezifische Bildoptionen werden nur noch gesendet, wenn der Provider sie auch anbietet. Ein alter Wert in einer ausgeblendeten Spalte kann den Aufruf so nicht mehr zerlegen.
- Alle Einstellungen des AddOns auf einer Seite; die Dokumentation als eigene Tabs (AddOn und Änderungswünsche).

### Entfernt

- Der Bildstil (Vivid/Natural) ist bei keinem mitgelieferten Provider mehr sichtbar — er war eine DALL-E-Option. Das Feld bleibt für einen per Extension Point ergänzten Provider mit solchen Modellen erhalten.
- Das Backend-Formular zum Anlegen eines Änderungswunsches von Hand: es war der zweite Einreichungsweg neben der REST-API und hätte dauerhaft mit ihr Schritt halten müssen.

## [1.0.0-beta3] – 2026-06-09

### Hinzugefügt

- Vektor-Embeddings über `generateEmbedding()`, einzeln oder als Liste.
- Vollständige OAuth-2.1-Schicht für den MCP-Server: Authorization Code mit PKCE, Refresh Tokens mit Rotation, Dynamic Client Registration, YCom als Identity-Provider.
- Scope-System mit Zuordnung YCom-Gruppe → Scopes im Backend; eigene Scopes über `AI_PLATFORM_OAUTH_SCOPES`.
- Login-, Consent- und Fehlermaske von `/oauth/authorize` als überschreibbare Fragmente.
- Ablaufdatum für OAuth-Clients und ein Schalter je MCP-Tool im Backend.

### Geändert

- **Breaking:** Der feste Bearer-Token in `rex_config` ist entfernt; die Authentifizierung läuft über OAuth. Bestehende Client-Konfigurationen müssen ihre `--header`-Zeile streichen — `mcp-remote` verhandelt selbst.
- **Breaking:** Der MCP-Endpunkt ist `/mcp`; der alte Aufruf über `?rex-api-call=ai_mcp` ist entfernt.
- Discovery und Weiterleitungen leiten Schema und Host aus dem tatsächlichen Request ab, damit der Server hinter einem Reverse Proxy oder Tunnel erreichbar bleibt.
- Klassen auf PSR-4-Namespaces umgestellt (`FriendsOfRedaxo\AiPlatform\…`).

## [1.0.0-beta1] – 2026-05-28

- Erste Fassung: Profile je Anwendungsfall, PHP-API für Text, Bildgenerierung und Bildverständnis, MCP-Server mit dem eingebauten Tool `redaxo_status`, Extension Points für MCP- und Agent-Tools.
