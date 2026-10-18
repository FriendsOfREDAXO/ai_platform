<?php

/**
 * Update-Skript des AddOns.
 *
 * **REDAXO fuehrt beim Update ueber den AddOn-Installer `install.php` nicht
 * aus.** `rex_install_package_update::run()` inkludiert genau diese Datei, legt
 * danach die neuen Dateien an die Stelle der alten, kopiert die Assets und
 * schreibt die Versionsnummer -- einen `install()`-Aufruf gibt es auf diesem Weg
 * nirgends. Alles, was ein bestehendes Projekt beim Hochziehen braucht, muss
 * deshalb hier passieren.
 *
 * Genau daran ist es einmal gescheitert: Die Tabellen der Aenderungswuensche
 * (`rex_ai_change_request`, `rex_ai_changeset`, `rex_ai_change_upload`) kamen
 * nach 1.0.0-beta3 dazu und standen nur in `install.php`. Ein Projekt, das von
 * beta3 ueber den Installer auf 1.0.0 ging, bekam die neuen Dateien, aber nicht
 * die neuen Tabellen -- und die Inbox starb mit
 * `Table 'rex_ai_change_request' doesn't exist`. Dasselbe gilt fuer die
 * OAuth-Tabellen aus beta3 bei einem Update von beta1.
 *
 * Deshalb wird `install.php` hier eingebunden, statt einzelne Schritte zu
 * wiederholen. Das ist sicher und bleibt es:
 *
 * - **Es ist durchgehend wiederholbar.** Tabellen und Spalten entstehen ueber
 *   `rex_sql_table::ensure*()`, Konfigurationswerte nur hinter einem
 *   `rex_config::has()`, und nichts darin loescht Daten.
 * - **Es benutzt nur Kernklassen.** Das ist die Bedingung dafuer, dass es hier
 *   ueberhaupt laufen kann: zu diesem Zeitpunkt sind noch die *alten* Dateien
 *   installiert, der neue `vendor/`-Baum ist nicht geladen und die neuen Klassen
 *   des AddOns existieren fuer den Autoloader nicht. Eine Klasse aus `lib/` hier
 *   anzufassen faellt genau dann um, wenn die Migration gebraucht wird.
 * - **`__DIR__` zeigt auf das neue Paket**, nicht auf die laufende Installation
 *   (der Installer inkludiert `../.new.<addon>/update.php`). Es laeuft also das
 *   Schema der neuen Version, was der ganze Zweck ist.
 *
 * Wer `install.php` erweitert, muss hier nichts nachziehen -- aber die beiden
 * Eigenschaften oben muessen erhalten bleiben, sonst bricht der Update-Pfad,
 * waehrend eine frische Installation weiter funktioniert.
 */

declare(strict_types=1);

// Schema und Konfiguration auf den Stand dieser Version bringen. Die Migration
// der Bildmodelle (`update-image-models.php`) haengt am Ende von `install.php`
// und laeuft damit mit -- nach dem Schema, was die richtige Reihenfolge ist.
require __DIR__ . '/install.php';
