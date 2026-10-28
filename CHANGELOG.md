# Changelog

Alle nennenswerten Änderungen an diesem AddOn.

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Geändert

- **Änderungswünsche öffnen oben auf der Seite.** Wer in *KI Änderungen* weit unten in der Liste einen Vorschlag anklickte, landete mitten in der Detailansicht, weil pjax bei Links die Scrollposition behält. Links und Formulare im Posteingang springen jetzt an den Seitenanfang.
- **Bei Änderungen an einem Bild steht das Bild in der Detailansicht.** Betrifft ein Vorschlag Metadaten einer Datei im Medienpool (etwa den Alternativtext) oder die Datei selbst, zeigt die Detailansicht unter „Vorschau" das Bild mit Dateiname, Größe und Abmessungen — wie bisher schon bei neuen Uploads. Wer einen Alternativtext prüft, muss das Bild dafür nicht mehr im Medienpool suchen.
- **Vorschau neben den Angaben.** Auf breiten Bildschirmen steht die Vorschau, auch die eines neuen Uploads, rechts neben Angaben und Begründung statt darunter. Der Diff rückt so nach oben. Unter 1200 px Breite steht sie wie bisher darunter.

### Behoben

- **Diff im Dark Mode unlesbar.** Die dunklen Farben für Diff, Filterleiste und Formular griffen nur, wenn das dunkle Theme ausgewählt *und* das System auf dunkel gestellt war. Folgte das Backend der Systemeinstellung oder war Dunkel fest gewählt, stand die helle Schrift von be_style auf den hellen Hintergründen, etwa beim Vorschlag in der Spalte „Vorschlag". Die Farben sind jetzt CSS-Variablen und werden wie in be_style für beide Fälle umgestellt.

## [1.2.0] – 2026-10-24

### Hinzugefügt

- **Hinweise auf widersprüchliche MCP-Einstellungen** (#10). *MCP Server > Einstellungen* warnt jetzt, wenn Einstellungen einzeln stimmen, zusammen aber nicht funktionieren: geschützte Tools, während „Authentifizierung erforderlich" auf Nein steht (Clients wie Claude Desktop sehen sie dann nie); Anmeldung erforderlich, aber YCom hat keine Anmeldeseite; Anmeldung erforderlich ohne ein einziges geschütztes Tool; ein Tool, dessen Scopes keine YCom-Gruppe hat.
- **401-Antworten von `/mcp` nennen den Grund** (#10). Neben „Authentication required" steht in `error.data` jetzt, ob die Server-Einstellung, ein geschütztes Tool oder ein ungültiger Token die Ursache ist, was zu tun ist und wo der OAuth-Ablauf beginnt. Die Meldung selbst und der `WWW-Authenticate`-Header bleiben unverändert.

### Geändert

- **Code-Style, Unit-Tests und statische Analyse laufen jetzt in der CI**, eingerichtet wie bei YCom und YForm: PHP-CS-Fixer mit der REDAXO-Konfiguration, PHPUnit und rexstan (PHPStan Level 5). Der Code ist einmal komplett nach dem REDAXO-Stil formatiert. Die Entwicklungswerkzeuge liegen in `.tools/` und gehen nicht ins Installer-Paket.

### Behoben

- **Änderungswünsche ließen sich im Backend nicht bearbeiten.** Das Korrigieren eines Vorschlags vor der Freigabe brach immer mit einem `TypeError` ab. Seit das Feld für den Entscheidungsweg (`reviewed_via`) hinzukam, übergaben zwei interne Kopiermethoden ihre Werte um eine Stelle verschoben. Gefunden hat das die neue statische Analyse.
- **Der Verbindungstest scheiterte an Modellen ohne `temperature`** (#21). Modelle wie die GPT-5-Reihe lehnen den Parameter ab; der Test meldete dann „Unsupported parameter: 'temperature'“, obwohl dasselbe Profil im Betrieb funktionierte. Der Test läuft jetzt über denselben Rückfall wie `generateText()`: einmal erneut ohne `temperature`. Modelle, die den Parameter kennen, bekommen weiterhin den eingestellten Wert.
- **Mit ausgeschaltetem MCP-Server lief der OAuth-Server weiter.** Der Schalter unter *MCP Server > Einstellungen* sperrte nur `/mcp`. Discovery, Client-Registrierung, Anmeldung und Token-Ausgabe antworteten weiter, ein Client konnte sich also registrieren und Tokens holen, mit denen er dann nichts anfangen konnte. Jetzt antworten bei ausgeschaltetem Server auch `/.well-known/oauth-*` und `/oauth/*` mit 503 — OAuth gibt es in diesem AddOn nur für den MCP-Server. Bereits ausgegebene Tokens bleiben gespeichert und gelten wieder, sobald der Server eingeschaltet wird.

## [1.1.2] – 2026-10-20

### Hinzugefügt

- **Feld „Beschreibung im Zustimmungsdialog"** unter *MCP Server > Einstellungen*. Der Satz erscheint dort neben dem Ersatz-Scope und erklärt der Person, worüber sie entscheidet — „mcp" allein sagt ihr nichts. Leerzeichen, Umlaute und Satzzeichen sind erlaubt, weil der Text nur angezeigt und nie als Scope gesendet wird.

### Behoben

- **Ein ungültiger Ersatz-Scope wurde stillschweigend verworfen.** Der eingetragene Wert wurde ungeprüft gespeichert, beim Lesen aber durch `mcp` ersetzt: Das Feld zeigte nach dem Speichern wieder `mcp`, ohne Hinweis, und wer einen lesbaren Namen eintrug, hielt die Einstellung für wirkungslos. Ungültige Werte werden jetzt abgewiesen, der Grund benannt und auf das Beschreibungsfeld verwiesen. Der Hilfetext nennt außerdem, dass neben Leerzeichen und Anführungszeichen auch Umlaute ausgeschlossen sind — Scopes sind im Protokoll leerzeichengetrennt (RFC 6749 §3.3), weshalb ein Bezeichner keine davon enthalten kann.

## [1.1.1] – 2026-10-18

### Behoben

- **Beim Update über den AddOn-Installer wurden fehlende Tabellen nicht angelegt.** REDAXO führt auf diesem Weg nur `update.php` aus, nie `install.php` — wo das gesamte Schema steht. Eine Installation, die von 1.0.0-beta3 oder 1.0.0 aktualisierte, bekam den neuen Code ohne die Tabellen der Änderungswünsche und lief in `Table 'rex_ai_change_request' doesn't exist`; von beta1 aus fehlten zusätzlich die OAuth-Tabellen. `update.php` bindet `install.php` jetzt ein. **Bereits aktualisierte Installationen holen das Schema mit einer Neuinstallation des AddOns nach** (*System > AddOns > ai_platform > Reinstall* oder `php redaxo/bin/console package:install ai_platform`); Daten gehen dabei nicht verloren.


## [1.1.0] - 2026-10-14

### Geändert

- **Symfony AI auf 0.14 angehoben** (`symfony/ai-*` von `^0.13` auf `^0.14`, installiert 0.14.0/0.14.1). Die Provider-Registry und der Agent-Pfad laufen unverändert (beide Testsuiten grün: 182 bzw. 6 Assertions); die aktualisierten Modell-Kataloge bringen neuere Modelle mit. `vendor/` und `composer.lock` sind mitgepflegt.
- **Übrige Abhängigkeiten auf aktuelle Patch-/Minor-Stände gehoben** (`symfony/http-client`, `symfony/serializer`, `symfony/string`, `symfony/uid`, die `symfony/polyfill-*`-Pakete u. a.). Keine Breaking Changes, beide Testsuiten weiter grün.
- **Die Anmeldung für den MCP-Zugriff läuft jetzt immer über YCom.** `/oauth/authorize` leitet einen nicht angemeldeten Besucher auf YComs Anmeldeseite (`article_id_login`) und holt ihn danach in den OAuth-Ablauf zurück. Damit funktionieren SAML, CAS und OAuth2, und YCom-Injections wie OTP oder ein erzwungener Passwortwechsel laufen, bevor ein Token entsteht.

### Hinzugefügt

- **Feld „Ersatz-Scope“** unter *MCP Server > Einstellungen*. Solange kein AddOn eigene Scopes anmeldet, wird dieser eine veröffentlicht und vergeben — nötig, weil Clients abbrechen, wenn die Scope-Liste leer ist, und sichtbar im Zustimmungsdialog, weshalb der Name wählbar sein sollte. Ohne Eingabe gilt `mcp`; ein Wert, der kein gültiges Scope-Token ist (RFC 6749 §3.3 erlaubt weder Leerzeichen noch Anführungszeichen), fällt auf die Vorgabe zurück.
- **Schalter „Immer verfügbar" je Tool** unter *MCP Server > Einstellungen*. Ein geschütztes Tool ist damit für jede angemeldete Person nutzbar, auch ohne passenden Scope — die Anmeldung bleibt nötig, nur die Scope-Prüfung entfällt. Scopes erreichen einen Nutzer über YCom-Gruppen; eine Installation ohne Gruppen konnte bisher keinen einzigen vergeben, womit jedes geschützte Tool für alle unerreichbar war, ohne dass im Backend etwas darauf hinwies.
- **Zeitlimit für HTTP-Aufrufe an die Provider**, einstellbar über `rex_config`: `http_timeout` (Vorgabe 300 Sekunden) ist das Fenster ohne Antwort, `http_max_duration` (Vorgabe 0 = unbegrenzt) die Gesamtdauer. Nötig, weil eine lange, nicht gestreamte Antwort die Verbindung hält, ohne etwas zu senden — der Standardwert des HTTP-Clients brach eine umfangreiche Übersetzung mit „Idle timeout reached" ab.

### Behoben

- **`generateText()` und `understandImage()` scheiterten bei Modellen, die `temperature` ablehnen.** Neuere Modelle (z. B. OpenAI-Reasoning-Modelle) verwerfen die Option mit „temperature is deprecated for this model" bzw. „unsupported parameter"; ein im Profil gespeicherter Temperatur-Wert ließ dann jeden Aufruf fehlschlagen — für eine Einstellung, die das Modell ohnehin ignoriert. Beide Methoden wiederholen den Aufruf jetzt einmal ohne `temperature`, wenn der Fehler darauf hinweist, statt abzubrechen.
- **`tools/call` lieferte eine Antwort, die Clients verwerfen, sobald ein Tool ein Array zurückgab.** `content` ist im Protokoll eine *Liste von Content-Blöcken*; durchgereicht wurde aber jedes Array, also auch das assoziative Ergebnis, das ein Tool zusammenstellt. Beim Aufrufer kam dort ein JSON-Objekt an, wo eine Liste stehen muss — kommentarlos verworfen, der Aufruf sah erfolgreich aus und die Antwort fehlte. Jetzt wird nur durchgereicht, was schon eine Blockliste ist; alles andere wird als JSON-Text verpackt.
- **`/oauth/authorize` antwortete mit 500, wenn die angemeldete Person in keiner YCom-Gruppe ist.** Die Scope-Auflösung baute aus der leeren Gruppenliste `... WHERE ycom_group_id IN ()` — ein SQL-Syntaxfehler, direkt nach erfolgreicher Anmeldung. Eine Installation ohne Gruppen traf das bei jedem Versuch. Der Testlauf deckt den Fall jetzt ab; bisher hatte der Testnutzer immer eine Gruppe, weshalb die Suite grün blieb.
- **Das Resource-Metadatendokument sprach unter jedem Pfad für `/mcp`.** RFC 9728 §3.1 hängt den Ressourcenpfad an den Well-known-Präfix: Unter `/.well-known/oauth-protected-resource/mcp` steht die Angabe für `/mcp`, unter `/.well-known/oauth-protected-resource` die für die Site. Ausgeliefert wurde beides Mal `<base>/mcp`, und die 401-Challenge verwies auf die Variante ohne Pfad. Ein Client vergleicht das gefundene `resource` mit der URL, die er anspricht, und steigt bei Abweichung aus — mit dem offiziellen SDK nachgestellt: „Protected resource … does not match expected …", noch bevor eine Anmeldeseite erscheinen konnte. Beide Dokumente werden jetzt getrennt beantwortet, die Challenge zeigt auf das pfadbezogene.
- **Clients brachen nach der Registrierung ab, ohne je eine Anmeldeseite zu zeigen.** Drei Abweichungen von den Spezifikationen kamen zusammen: Der `issuer` trug einen Schrägstrich zu viel, den RFC 8414 §3.3 beim Vergleich nicht erlaubt; die 401-Challenge nannte `resource` statt `resource_metadata` (RFC 9728 §5.1); und weder kündigten die Discovery-Dokumente `scopes_supported` an noch gab die Registrierung das gewährte `scope` zurück (RFC 7591 §3.2.1) — ein Client, der nach einem Scope fragt und keine Antwort bekommt, hat nichts, was er in die Authorize-URL schreiben könnte. Hat eine Installation keine eigenen Scopes angekündigt, wird jetzt ein neutraler Marker veröffentlicht und auch tatsächlich gewährt.
- **Die OAuth-Tests liefen nur im Standard-Layout.** Sie suchten den Core über eine feste Zahl von Verzeichnisebenen und die Konfiguration unter `data/core/config.yml`. Ein Projekt mit eigenem Path-Provider legt `src/` an die Projektwurzel und die Daten nach `var/data` — dort scheiterten sie vor dem ersten Test. Der Core wird jetzt durch Aufwärtssuche gefunden, die Konfiguration über beide üblichen Orte.
- **Die `.rex-docs`-Styles des AddOns färbten im Dark Mode in die Doku-Seiten anderer AddOns ab.** Sie sind entfernt.

### Entfernt

- **Die eigene Passwortmaske unter `/oauth/authorize`.** Sie prüfte nur Name und Passwort gegen die Datenbank: für per SAML oder CAS angelegte Nutzer gab es dort nichts einzugeben, und sie ging an YComs Injections vorbei — ein MCP-Token konnte ohne zweiten Faktor entstehen, während das Frontend geschützt blieb. Ist in YCom keine Anmeldeseite hinterlegt, antwortet `/oauth/authorize` mit einem benannten Fehler, und die Einstellungsseite warnt.

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
