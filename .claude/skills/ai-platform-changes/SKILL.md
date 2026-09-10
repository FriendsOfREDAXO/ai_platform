---
name: ai-platform-changes
description: Änderungswünsche im REDAXO-AddOn ai_platform — wie ein Agent oder AddOn Inhaltsänderungen einreicht, die erst nach redaktioneller Freigabe wirksam werden. Use when an agent should modify REDAXO content it has no write access to, when adding a new change type (Target/Payload/Handler), or when debugging why a proposal is refused, stale or blocked.
---

# Änderungswünsche einreichen und verstehen

Das AddOn `ai_platform` nimmt **Änderungswünsche** an: ein Vorschlag beschreibt, was geändert werden soll und warum. Geschrieben wird nichts, bis ein Redakteur im Backend freigibt.

Damit kann ein Agent, der nur lesenden Zugriff hat, trotzdem etwas bewirken — ohne dass ihm jemand Schreibrechte gibt.

## Der Ablauf in drei Schritten

1. **Nachsehen, was es gibt.** Welche Typen, welche Felder — das unterscheidet sich pro Installation.
2. **Ist-Zustand lesen.** Ein Vorschlag ohne Kenntnis des Alten ist wertlos.
3. **Einreichen, mit Begründung.** Die Begründung ist das, worauf der Redakteur seine Entscheidung stützt.

### Als Agent (Tools)

Ein Agent, der über `Service::createAgent()` läuft, hat drei Tools:

| Tool | Zweck |
|---|---|
| `redaxo_describe_change_types` | Welche Typen, Ziel-Felder und schreibbaren Felder existieren hier |
| `redaxo_read_current` | Ist-Zustand eines Ziels lesen |
| `redaxo_propose_change` | Vorschlag einreichen |

`redaxo_propose_change` nimmt `type`, `operation`, `target` (JSON), `fields` (JSON), `reason` und optional `changeset`.

**Immer erst `redaxo_describe_change_types` aufrufen.** Metainfo-Felder und YForm-Tabellen sind pro Website verschieden; geratene Feldnamen erzeugen nur Arbeit für den Redakteur.

### Aus PHP

```php
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\Payload\SlicePayload;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\Target\SliceTarget;

$service = ChangeService::getInstance();

$current = $service->read(SliceTarget::existing(sliceId: 345, articleId: 12));

$id = $service->propose(
    target:  SliceTarget::existing(sliceId: 345, articleId: 12),
    payload: (new SlicePayload())->value(1, 'Neue Überschrift'),
    reason:  'Überschrift war doppelt vergeben, kollidierte mit Artikel 12.',
    source:  Source::php('mein_addon', 'SEO-Optimierung'),
    changesetKey: 'seo-lauf-2026-08',
);
```

Löschungen laufen über `proposeDelete()` und brauchen keine Werte.

### Über REST (Agent von außen, nur mit Token)

Für einen Agent, der nicht in diesem REDAXO läuft und nichts hat als ein
Bearer-Token des api-AddOns. Die Routen kommen aus `ai_platform`, angemeldet
beim api-AddOn — dort ist nichts geändert.

| Methode | Pfad | Scope | Zweck |
|---|---|---|---|
| `GET` | `/api/ai_platform/changes/describe` | `ai_platform/changes/read` | **Ohne Parameter:** welche Typen, Operationen, Feldnamen es hier gibt. **Mit `type` + `target`** (JSON): Ist-Zustand dieses Ziels |
| `GET` | `/api/ai_platform/changes[/{id}]` | `ai_platform/changes/requests` | Eigene Wünsche — ohne ID die Liste, mit ID Status, Situationsbefund, Begründung einer Ablehnung |
| `POST` | `/api/ai_platform/changes` | `ai_platform/changes/propose` | Einreichen, einzeln oder als Batch |
| `POST` | `/api/ai_platform/changes/uploads` | `ai_platform/changes/upload` | Datei für den Medienpool zwischenspeichern, Name reservieren |
| `POST` | `/api/ai_platform/changes/deletions` | `ai_platform/changes/propose_delete` | Löschvorschläge |
| `POST` | `/api/ai_platform/changes/approvals` | `ai_platform/changes/approve` | Eigene Wünsche selbst freigeben |
| `POST` | `/api/ai_platform/changes/withdrawals` | `ai_platform/changes/withdraw` | Eigene Wünsche zurückziehen |

**Du brauchst selten alle.** `propose` allein reicht, wenn die Feldnamen bekannt
sind: der Server liest den Ausgangszustand selbst, es muss nichts vorher
abgerufen werden. Praktisches Minimum ist `read` + `propose`; `requests` kommt
dazu, wenn du auf eine Ablehnung reagieren sollst, `approve` nur, wenn du
unbeaufsichtigt schreiben darfst.

Dass es überhaupt mehrere Routen sind, liegt an der Rechteprüfung des
api-AddOns: sie greift **pro Route**, eine Route ist ein Scope, und der Scope
steht nicht im Body. Zusammenlegen heißt deshalb Rechte zusammenlegen — weshalb
Löschen und Freigeben getrennt bleiben.

```bash
curl -X POST https://example.org/api/ai_platform/changes \
  -H 'Authorization: Bearer <token>' -H 'Content-Type: application/json' \
  -d '{"type":"slice","operation":"update",
       "target":{"article_id":12,"slice_id":345},
       "fields":{"value1":"Neue Überschrift"},
       "reason":"Überschrift war doppelt vergeben, kollidierte mit Artikel 12."}'
```

Ein Batch schickt statt der Einzelfelder ein `proposals`-Array; ein `changeset`
auf oberster Ebene gilt für alle Elemente, die keins eigenes nennen.

```json
{"changeset": "alt-texte-august", "proposals": [ {...}, {...} ]}
```

**Die Antwortcodes bedeuten etwas.** `201` alle angenommen, `207` teils
angenommen — dann steht in jedem Element `ok` und bei Fehlern `error` samt
`index` —, `422` keins angenommen. Ein `207` als Erfolg zu lesen ist der Fehler,
den diese Trennung verhindern soll.

Ein Batch hat **keine Obergrenze**; `meta.batch_limit` in `/describe` ist
entsprechend `null`. Was ein Batch anrichtet, entscheidet sich an seinen
Schreibvorgängen, nicht an ihrer Anzahl.

`400` heißt kaputter Body (kein JSON, `proposals` kein Array, leerer Batch),
`401` Token fehlt oder trägt den Scope nicht, `500` ein unerwarteter Fehler
serverseitig.

**Ein `404` auf einen dieser Pfade heißt nicht zwangsläufig „falsche URL".** Ist
die Funktion abgeschaltet, meldet `boot.php` die Routen gar nicht an — eine
abgeschaltete Funktion hat bewusst überhaupt kein HTTP-Interface, nicht einmal
eines, das Fehler zurückgibt. Ein `404` auf allen Pfaden gleichzeitig bedeutet
also: Funktion aus, oder das api-AddOn ist nicht aktiv. Ein `404` auf genau einem
Pfad bedeutet Tippfehler.

**Löschungen haben eine eigene Route mit eigenem Scope**, damit ein Token
vorschlagen darf, ohne löschen vorschlagen zu dürfen. `operation: "delete"` auf
der normalen Route wird abgewiesen und verweist dorthin.

**Die Quelle bestimmt das Token, nicht der Request.** `source_key` ist
`api-token:<id>`, egal was im Body steht. Daran hängen der Filter im Eingang und
später die Annahmequote; ein frei wählbarer Quellname würde beides wertlos
machen. Ein `source_key` im Body wird ignoriert, nicht übernommen.

**Nachfassen statt wiederholen.** `GET /{id}` liefert bei einer Ablehnung
`review_note` — die Begründung des Redakteurs. Und Vorsicht: ein zweiter
**Änderungs**vorschlag für dasselbe Ziel überholt den ersten
(`status: superseded`), er ergänzt ihn nicht. Wer bei jedem Lauf blind neu
einreicht, löscht damit seine eigenen offenen Wünsche.

**Anlegen ist davon ausgenommen.** Ein Create-Ziel benennt einen *Ort*, kein
Ding: sechs neue Unterkategorien unter derselben Kategorie haben alle das Ziel
`{parent_id: 40, clang_id: 1}`. Würden sie sich überholen, überlebte genau eine.
Also: mehrere Creates am selben Ort einreichen ist in Ordnung, mehrere Updates
auf dasselbe Feld nicht.

**YForm-Feldnamen gibt es in zwei Formen.** `/describe` liefert sie
tabellenqualifiziert (`rex_company.name`), weil eine flache Liste alle erlaubten
Tabellen abdeckt und `name` in mehreren vorkommt. Beim Einreichen sind **beide**
Formen erlaubt — der qualifizierte Name genau wie aus `/describe`, oder der nackte
Spaltenname. Ein Präfix, das eine *andere* Tabelle nennt als das Ziel, wird
abgewiesen: das heißt, der Aufrufer hat die Tabelle verwechselt, und das Präfix
stillschweigend abzuschneiden würde den Wert in die falsche Spalte schreiben.

### Eigene Vorschläge zurückziehen

`POST /api/ai_platform/changes/withdrawals` mit `{"id": 123}` oder
`{"ids": [...]}`, dazu ein `reason`.

**Wer einen Fehler bemerkt, räumt ihn selbst weg.** Einen falschen Vorschlag im
Eingang liegen zu lassen, damit ein Redakteur ihn ablehnt, macht aus dem eigenen
Fehler die Arbeit eines anderen.

Gelöscht wird nichts: der Eintrag wechselt auf den Status `withdrawn`. Der ist
bewusst nicht dasselbe wie `rejected` — abgelehnt heißt, ein Mensch hat
hingesehen und Nein gesagt, und dieses Urteil ist etwas anderes als eine
Selbstkorrektur.

Es geht nur bei **eigenen** Wünschen und nur solange sie `pending` sind. Nach
einer Freigabe entscheidet nicht mehr der Einreicher.

### Selbst freigeben (nur wenn die Installation es erlaubt)

`POST /api/ai_platform/changes/approvals` mit `{"id": 123}` oder
`{"ids": [123, 124]}`. Damit wird wirklich geschrieben — es ist die einzige
Route, die das tut.

Der Zweck ist eng: **eine Struktur muss existieren, bevor Vorschläge darauf
zeigen können.** Eine Unterkategorie braucht `parent_id`, ein Slice eine
`article_id`, und beides gibt es erst nach dem Anlegen. Für eine Rubrik mit
Unterrubriken und Inhalten heißt das sonst: einreichen, auf einen Menschen
warten, IDs lesen, weiter.

**Ob es geht, hängt allein am Scope.** Hat das Token
`ai_platform/changes/approve`, sind alle Typen und alle Operationen freigegeben —
anlegen, ändern, löschen, auch direkt öffentlich. Hat es ihn nicht, kommt 401.
Eine zusätzliche Einstellung gibt es nicht, und das ist Absicht: die Vergabe des
Scopes *ist* die Entscheidung. Ein zweiter Schalter, der dieselbe Frage stellt,
schafft nur einen Zustand, in dem beide Antworten sich widersprechen — Scope
vergeben, Funktion „aus", und ein 403, der wie ein Fehler aussieht.

#### Wer darf das gerade? Und wie stellt man es ein?

Beides passiert **im API-AddOn**, nicht hier: *API → Token*, dort am Token den
Scope `ai_platform/changes/approve` setzen oder entfernen. Wer wissen will, ob in
einer Installation unbeaufsichtigt geschrieben werden kann, sieht in dieser Liste
nach, welche Token den Scope tragen.

Ein Agent kann es selbst herausfinden, ohne es zu versuchen: `GET /describe` nennt
unter `meta.api_approval` den nötigen Scope und die geltenden Regeln.

Zwei Notbremsen gibt es unabhängig davon:

* **Scope entziehen** — wirkt sofort, betrifft nur dieses Token.
* **KI Änderungen abschalten** (*KI Platform → Einstellungen*) — schaltet die
  ganze Funktion ab, inklusive Eingang, Menüpunkt und allen Routen.

Zwei Regeln bleiben und sind nicht abschaltbar: es gehen **nur eigene**
Vorschläge, und ein blockierter Zustand (Ziel weg, Verweis kaputt, Ziel
geändert) bleibt blockiert — `force` existiert hier nicht, ein `"force": true`
im Body wird ignoriert.

Die Antwort enthält bei Erfolg `created` mit den erzeugten IDs — genau das,
worauf der nächste Vorschlag zeigen kann. `GET /describe` nennt unter
`meta.api_approval`, was gilt.

Antwortcodes: `200` alle freigegeben, `207` teils, `422` keine (mit Grund je
Element), `401` Scope fehlt. Keine Obergrenze für die Anzahl der IDs.

Jede so entschiedene Änderung wird mit `reviewed_via = api` gespeichert und im
Eingang als *ohne Sichtung freigegeben* gekennzeichnet. Das ist keine Formalie:
ein Redakteur muss im Nachhinein erkennen können, was ohne ihn passiert ist. Die
Einträge werden nie gelöscht.

**Nicht über MCP.** Der `/mcp`-Endpunkt trägt die Inhalte und Tools, die ein
Projekt für seine Kunden anbietet — keine CMS-Interna. Änderungswünsche sind
CMS-Interna. Wer sie in seinem Projekt trotzdem über MCP anbieten will,
registriert dort ein eigenes Tool; der Standardweg von außen ist REST.

## Bilder und Dateien anbieten

Die einzige Route, die Bytes annimmt. **Zwei Schritte**, und der zweite geht nicht
ohne den ersten.

```bash
# 1) Datei zwischenspeichern. Nichts landet im Medienpool.
curl -X POST https://example.org/api/ai_platform/changes/uploads \
  -H 'Authorization: Bearer <token>' -F 'file=@bulli.jpg'
```

```json
{"data": {"upload": "up_7f3a…", "filename": "bulli.jpg", "mime": "image/jpeg",
          "bytes": 482113, "width": 2400, "height": 1600,
          "expires_at": "2026-08-23T14:02:00+02:00"},
 "meta": {"staged": true, "in_media_pool": false, "next": "POST … ",
          "reservation_hours": 24}}
```

```bash
# 2) Den Wunsch einreichen, der auf das Handle zeigt.
curl -X POST https://example.org/api/ai_platform/changes \
  -H 'Authorization: Bearer <token>' -H 'Content-Type: application/json' \
  -d '{"type":"media","operation":"create",
       "target":{"category_id":3,"filename":"bulli.jpg"},
       "fields":{"upload":"up_7f3a…","title":"Bulli am Strand"},
       "reason":"Beitragsbild für den neuen Artikel."}'
```

Statt `multipart/form-data` geht auch ein roher Body plus `?filename=` — der Name
ist dann Pflicht, aus Bytes lässt er sich nicht raten.

**Der Dateiname aus Schritt 1 ist der, den du benutzen musst.** Er ist reserviert,
und der Wunsch muss ihn genau so nennen; eine Abweichung wird abgewiesen statt
still korrigiert. Ist der Name schon belegt, kommt eine `422` — dann einen anderen
wählen, nicht auf eine automatische Umbenennung hoffen.

Grenzen: 32 MB, erlaubte Endung und echter MIME-Typ müssen zueinander und zu den
Regeln des Medienpools passen. Ein `.php`, das `.jpg` heißt, wird an seinem
MIME-Typ erkannt. Die Reservierung verfällt nach 24 Stunden, wenn kein Wunsch
darauf zeigt — danach ist die Datei weg und der Name wieder frei.

### Reihenfolge: Bild zuerst freigeben, dann den Slice

**Das ist die Stelle, an der es sonst schiefgeht, und ein Changeset löst es
nicht.** Ein Slice-Wunsch mit `media1` wird **beim Einreichen** gegen
`rex_media::get()` geprüft. Zeigt er auf ein Bild, das nur vorgeschlagen ist,
wird er sofort abgewiesen — nicht erst bei der Freigabe. Beides in einen Batch zu
packen hilft also nicht: der Batch wird elementweise geprüft, das Bild geht durch
(`ok: true`), der Slice nicht.

Die Fehlermeldung sagt es auch und unterscheidet die beiden Fälle:

* *„… ist noch nicht im Medienpool — sie wartet als eigener Änderungswunsch"* →
  du hast das Bild vorgeschlagen, aber es ist nicht freigegeben. Reihenfolge.
* *„… existiert nicht mehr im Medienpool"* → die Datei ist wirklich weg.
  Falscher Name oder gelöscht.

**Der verlässliche Ablauf, zwei Runden:**

```
1. POST /changes/uploads            → Handle + reservierter Name
2. POST /changes                    → Medien-Create, zeigt auf das Handle
3. warten bis freigegeben:
   - mit approve-Scope: POST /changes/approvals mit der ID → sofort erledigt
   - ohne: GET /changes/<id> pollen, bis "applied" gemeldet wird
4. POST /changes                    → der Slice mit media1: "<reservierter Name>"
```

Den reservierten Namen kennst du bereits ab Schritt 1 — das ist der Grund, warum
die Reservierung existiert. Du musst also nicht abwarten, um den Namen zu
erfahren, nur um ihn *verwenden* zu dürfen.

**Struktur verhält sich genauso.** Ein Artikel-Create nennt seine Kategorie über
`category_id`, und `ArticleHandler::checkReferences()` prüft beim Einreichen, ob
die existiert. Eine Kategorie und einen Artikel darin im selben Changeset
einzureichen scheitert also aus zwei Gründen: die Prüfung greift sofort, und die
ID der neuen Kategorie ist noch nicht bekannt. **Jede Ebene braucht eine eigene
Runde** — anlegen, freigeben, die ID aus `created` lesen, weiter.

Die Antwort der Freigabe liefert sie mit:

```json
{"data":[{"id":3820,"ok":true,"created":{"category_id":347}}]}
```

**Wofür ein Changeset dann gut ist:** es fasst zusammen, was fachlich
zusammengehört, und gibt dem Redakteur einen Sammel-Freigabeknopf — bei einem
Lauf über vierzig Slices der Unterschied zwischen einer Entscheidung und vierzig.
`approveChangeset()` arbeitet in Einreichungsreihenfolge ab, das ist Zusage und
nicht Zufall. Was es **nicht** kann, ist eine Abhängigkeit auflösen, die schon
beim Einreichen geprüft wird — und das sind alle: Medienverweise, Linkziele,
Zielkategorien, Module, Templates.

**Eine bestehende Datei ersetzen geht nicht.** Nur neu anlegen, Titel und
Kategorie ändern, löschen. Alt-Text und Copyright sind Metainfo → Typ `meta`.

## Die sechs Typen

| `type` | Ziel adressiert über | Schreibbare Felder |
|---|---|---|
| `slice` | `article_id` + `slice_id` (Update/Delete) bzw. `article_id`, `clang_id`, `module_id`, `ctype_id`, `priority` (Create) | `value1`–`value20`, `media1`–`media10`, `medialist1`–`medialist10`, `link1`–`link10`, `linklist1`–`linklist10` |
| `article` | `article_id` + `clang_id`, bei Create `category_id` | `name`, `priority`, `template_id`, `status` |
| `category` | `category_id` + `clang_id`, bei Create `parent_id` | `catname`, `catpriority`, `status` |
| `meta` | `carrier` (`article`/`category`/`media`/`clang`) + je nach Träger `article_id`/`category_id`/`filename`/`clang_id` | alle definierten `art_*` / `cat_*` / `med_*` / `clang_*`-Felder |
| `media` | `filename`, bei Create zusätzlich `category_id` | `title`, `category_id`, bei Create `upload` (Handle aus `/uploads`) |
| `yform` | `table` + `dataset_id`, bei Create nur `table` | alle Value-Felder der Tabelle |

Die Feldlisten entsprechen genau dem, was die REDAXO-Serviceklassen schreiben. Nichts darüber hinaus ist erfunden verfügbar.

**Eine Kategorie trägt zwei Namen, und sie liegen in zwei Typen.** Der
Startartikel einer Kategorie ist ein Artikel wie jeder andere und hat deshalb
`name` (die Überschrift der Seite) *und* `catname` (den Eintrag in der
Navigation). `type: "category"` schreibt nur `catname`. Wer eine Kategorie in
einer zweiten Sprache benennt, braucht daher zwei Wünsche auf dieselbe ID:

```json
{"type":"category","operation":"update","target":{"category_id":347,"clang_id":2},"fields":{"catname":"Appeal"}}
{"type":"article", "operation":"update","target":{"article_id":347, "clang_id":2},"fields":{"name":"Appeal"}}
```

Nur den ersten zu schicken ist der wahrscheinlichere Fehler, und er fällt nicht
auf: die Navigation ist dann englisch, die Seitenüberschrift noch deutsch, und
jeder der beiden Wünsche sieht im Diff für sich richtig aus.

**Häufige Verwechslung:** Alt-Text, Copyright und Bildbeschreibung sind **Metainfo** (`med_*`), nicht Felder des Mediums. Sie laufen über `type: "meta"` mit `carrier: "media"`, nicht über `type: "media"`.

## Was einen Vorschlag gut macht

**Die Begründung trägt die Entscheidung.** Der Redakteur sieht Typ, Ziel, einen Feld-Diff und diesen Text. „Text verbessert" hilft ihm nicht. „Überschrift war identisch mit Artikel 12, das schadet der Auffindbarkeit" schon.

**Ein Vorschlag pro Sache.** Ein neuer Vorschlag für dasselbe Ziel markiert ältere offene automatisch als überholt — nachfassen ersetzt also, es ergänzt nicht.

**Zusammengehöriges bündeln.** Gleicher `changeset`-Schlüssel heißt: der Redakteur kann alles auf einmal freigeben. Bei einem Lauf über 40 Artikel ist das der Unterschied zwischen einer Entscheidung und vierzig.

**Nur wirklich Geänderte Felder setzen.** Ein Feld, das nicht gesetzt wird, bleibt unberührt. Ein Feld auf `""` zu setzen, leert es — das ist eine Aussage, nicht ein Versehen.

## Felder, die nicht gesetzt werden können

Bei YForm gibt es Felder, die YForm selbst pflegt: `datestamp` (Zeitstempel) und `showvalue` (reine Anzeige). Ein Vorschlag darauf wird abgewiesen — YForm würde ihn beim Speichern überschreiben oder ignorieren, und ein still verworfener Wert sieht wie ein angenommener aus. `redaxo_describe_change_types` listet sie nicht mit auf.

Gleiches gilt für n:m-Relationen über eine Zwischentabelle: sie haben keine eigene Spalte in der Haupttabelle und sind deshalb nicht per Wertzuweisung änderbar.

### Datenmenge und Aufbewahrung

Es gibt **keine Obergrenze** für die Größe eines Vorschlags, keine Begrenzung der
offenen Wünsche pro Quelle und keine Obergrenze für einen Batch. Ein langer
Artikel ist ein langer Artikel.

Gelöscht wird nie. Der Cronjob „KI Änderungen: Datenmenge alter Wünsche
reduzieren“ leert bei lange entschiedenen Wünschen nur die Nutzlast und die
Momentaufnahmen — wer wann was entschieden hat, bleibt dauerhaft lesbar. Offene
Wünsche werden nie angefasst.

Ein Vorschlag, dessen `payload` bei `GET /{id}` leer ist, war also nicht kaputt:
er ist alt und wurde verkleinert.

## Wenn die Funktion abgeschaltet ist

Ist „KI Änderungen" unter *KI Platform > Einstellungen* inaktiv, ist sie vollständig inaktiv: `propose()` und `approve()` werfen, die Agent-Tools sind nicht registriert, der Menüpunkt existiert nicht. Ein Vorschlag lässt sich dann nicht einreichen — die Fehlermeldung lautet „Änderungswünsche sind deaktiviert."

## Warum ein Vorschlag abgewiesen wird

Beim Einreichen:

| Meldung | Ursache |
|---|---|
| „ist kein beschreibbares Feld" | Feldname existiert nicht für diesen Typ — `redaxo_describe_change_types` sagt, welche es gibt |
| „Unbekanntes Metainfo-Feld" | Feld nicht definiert, oder falsches Präfix für den Träger |
| „nicht für Änderungswünsche freigegeben" | YForm-Tabelle nicht freigeschaltet (Admin-Einstellung) |
| „führt seine Werte als PHP aus“ | Das Modul gibt `REX_VALUE[… output=php]` aus — ein Slice dafür wäre Code, nicht Inhalt. Nicht freischaltbar, auch nicht von einem Admin. |
| „existiert nicht mehr" | Verweis auf gelöschtes Medium, Artikel, Modul, Template |

Ein Vorschlag, dessen Verweis schon beim Einreichen ins Leere zeigt, wird sofort abgewiesen. Das ist Absicht: dann kann der Agent es korrigieren, statt dass es beim Redakteur landet.

## Was zwischen Einreichung und Freigabe passieren kann

Ein Wunsch liegt Stunden oder Tage im Eingang. Vier Zustände werden unterschieden:

| Zustand | Bedeutung | Folge |
|---|---|---|
| Ziel weg | Artikel, Slice, Medium oder Datensatz gelöscht | Wunsch verfällt |
| Verweis kaputt | Vorschlag zeigt auf Gelöschtes | gesperrt, **nicht** übersteuerbar |
| Ziel geändert | Felder weichen vom Stand bei Einreichung ab | gesperrt, bewusst übersteuerbar |
| Umfeld geändert | Ziel unberührt, Umgebung nicht | nur Warnung |

Ein kaputter Verweis ist bewusst nicht übersteuerbar: es gibt kein „trotzdem anwenden", das korrekte Daten erzeugt.

„Umfeld geändert" ist die einzige Prüfung, die ein **Anlegen** hat — ein Create hat kein Ziel, dessen Felder man vergleichen könnte. Deshalb wird die Umgebung festgehalten, bei einem neuen Slice die IDs der bereits vorhandenen.

Über `ChangeService::getRequest($id)` bzw. das Tool-Ergebnis lässt sich der Status abfragen. Wurde abgelehnt, steht die Begründung des Redakteurs in `reviewNote` — daraus lässt sich ein besserer zweiter Versuch machen.

## Einen neuen Änderungstyp beitragen

Drei Klassen und eine Registrierung. Kein Kernfile wird angefasst.

1. **Target** (`TargetInterface`, meist via `AbstractTarget`): wohin. Die Named Constructors legen die Operation fest — `existing()` → Update, `createIn()` → Create, `forDeletion()` → Delete. Damit ist „anlegen, was schon existiert" nicht formulierbar.
2. **Payload** (`AbstractPatchPayload`): was. Typisierte Setter, die Wertebereiche und Formate beim Setzen prüfen. Nur gesetzte Felder sind Teil des Vorschlags.
3. **Handler** (`AbstractHandler`): `readCurrent()`, `validate()`, `canApprove()`, `apply()`; optional `readContext()`, `checkReferences()`, `diffFields()`, `revert()`.

```php
rex_extension::register('AI_PLATFORM_CHANGE_HANDLERS', function (rex_extension_point $ep) {
    $handlers = $ep->getSubject();
    $handlers['mein_typ'] = new MeinHandler();
    return $handlers;
});
```

Drei Dinge, die dabei nicht optional sind:

**`canApprove()` prüft die echten REDAXO-Rechte des freigebenden Users.** Ohne das wird die Freigabe ein Weg, an den eigenen Rechten vorbeizuschreiben — ein Agent schlägt eine Änderung an einer Kategorie vor, für die der Redakteur keine Berechtigung hat, und die Freigabe schreibt sie trotzdem.

**`readCurrent()` muss alles enthalten, was ein Payload anfassen kann.** Sonst bleibt eine Änderung an einem nicht erfassten Feld unbemerkt.

**Payload-Whitelisting im `apply()`.** Niemals einen rohen Payload in ein SQL-Update mergen. Der Payload kommt von außen; `id`, `createuser` und Konsorten müssen unerreichbar bleiben.

Wer den Typ auch über flache Arrays ansprechbar machen will (Agent-Tool, REST-Routen), erweitert `ChangePayloadBuilder`.

## Fallstricke im REDAXO-Unterbau

Diese haben beim Bau Fehler verursacht und sind der Grund für Code, der auf den ersten Blick umständlich wirkt:

* **`rex_article_service::editArticle()` patcht nicht.** Es verlangt `name` bei jedem Aufruf und schreibt `name`, `template_id`, `priority` unbedingt. Fehlende Werte müssen aus dem Snapshot nachgefüllt werden, sonst leert ein „nur Priorität ändern" den Artikelnamen.
* **`editArticle()` ersetzt ein unerlaubtes Template still.** Wird vorher abgewiesen, weil ein Diff, der Template A verspricht und B schreibt, schlimmer ist als keiner.
* **Prioritäten sind relative Positionen.** `organizePriorities()` nummeriert Geschwister lückenlos neu — aus „Priorität 6" wird in einer Ein-Artikel-Kategorie eine 1. Nach dem Anwenden wird deshalb erneut gelesen und der Ist-Wert protokolliert.
* **`rex_category_service::editCategory()` patcht dagegen** und nutzt `catname`/`catpriority`. Daher getrennte Klassen für Artikel und Kategorie.
* **Es gibt kein `editSlice()` im Core.** Ein Slice-Update ist SQL plus die Extension-Point-Sequenz, die `pages/content.php` feuert.
* **`rex_article_slice` hat `value1`–`value20`.** Das `api`-AddOn deckt nur 1–19 ab; nicht kopieren.
* **`rex_yform_manager_dataset` poolt Instanzen**, und `save()` schreibt den gesamten geladenen Datensatz. Aus warmem Cache gelesen wäre der Snapshot veraltet, und beim Schreiben würden Felder zurückgesetzt, die niemand vorgeschlagen hat. Snapshots kommen deshalb per SQL, und vor dem Schreiben wird die Instanz verworfen.
* `rex_url::*` **escapt den Parameter-Trenner selbst.** Ein zusätzliches `rex_escape()` macht daraus `&amp;amp;`, und der Parameter kommt nie an.

## Wo die Einstellungen liegen

**Eine Seite: *KI Platform → Einstellungen*** (`pages/settings.php`), zusammen mit
den Standardprofilen. Zuerst der Master-Schalter, darunter — **nur wenn er aktiv
ist** — das Verhalten bei geändertem Ziel und die erlaubten YForm-Tabellen, dazu
zwei Lesetabellen (registrierte Handler, Menge je Status).

Das Ein- und Ausblenden macht `assets/changes.js` am Schalter, der Wechsel wirkt
also sofort ohne Speichern. Der Server setzt nur den Anfangszustand (`hidden`),
damit bei abgeschalteter Funktion nichts aufblitzt.

Die Einstellungen sind zweimal umgezogen: von `ai_changes/settings` unter
`ai_platform/settings/changes`, dann in die Einstellungsseite selbst. Beide Male
aus demselben Grund — der Schalter, der über die Existenz der Funktion
entscheidet, stand auf einer anderen Seite als das, was er steuert.

**Beim Ändern der Seite eine Falle beachten.** Die Detailfelder bleiben im DOM und
damit **im POST**, versteckt oder nicht — und das ist die sichere Hälfte: ein
verstecktes Feld sendet den Wert, mit dem es gerendert wurde, ein Speichern bei
ausgeblendetem Block schreibt also die gespeicherten Werte unverändert zurück.

Wer daraus bedingtes Rendern macht, baut den stillen Fall ein: nicht gerenderte
Felder stehen auch nicht im POST, und wer sie trotzdem ausliest, setzt die
Stale-Policy auf den Standard zurück und leert die YForm-Liste — ohne Fehler, ohne
Warnung, eine Sicherheitseinstellung wieder offen. So war es kurzzeitig, abgesichert
über ein Markerfeld `changes_details_present`; der Marker ist mit dem bedingten
Rendern gegangen. `change-pages-test.php` prüft beide Hälften.

## Wenn die Oberfläche nicht so aussieht wie erwartet

REDAXO liefert Assets nicht aus `assets/` des AddOns aus, sondern aus einer Kopie unter `assets/addons/ai_platform/`, die beim Installieren entsteht. Änderungen an CSS oder JS wirken erst nach:

```bash
echo "y" | redaxo/bin/console package:install ai_platform
```

Ein vergessener Sync sieht aus wie ein CSS-Bug: Abstände fehlen, Felder werden nicht ein- und ausgeblendet. Vor der Fehlersuche im Stylesheet also erst die ausgelieferte Kopie mit der Quelle vergleichen.

## Tests

Vor Änderungen an `lib/Change/` laufen lassen:

```bash
php .claude/tests/change-storage-test.php      # Payload-Guards, Persistenz, Supersede
php .claude/tests/change-handlers-test.php     # ein echter Durchlauf je Handler
php .claude/tests/change-situations-test.php   # gelöschte Ziele, kaputte Verweise, Nebenläufigkeit
php .claude/tests/change-pages-test.php        # Seitenbaum, Rechte, Rendering, XSS, Links, Datei-Vorschau
bash .claude/tests/api-changes-test.sh         # alle sieben REST-Routen über HTTP
```

Alle laufen gegen die echte Datenbank und räumen hinter sich auf.

`change-pages-test.php` löst zusätzlich **jeden `page=`-Link jeder gerenderten
Seite gegen den echten Seitenbaum auf**. Tote Links rendern nämlich fehlerfrei:
nach dem ersten Umzug zeigte die Einstellungsseite auf zwei Seiten, die es nicht
mehr gab, und aufgefallen ist es erst beim Klicken.
