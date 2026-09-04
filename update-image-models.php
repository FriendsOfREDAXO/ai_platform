<?php

/**
 * Stored image-generation settings, brought in line with Symfony AI 0.13.
 *
 * Ausgefuehrt aus `update.php` (Weg ueber den AddOn-Installer) und aus
 * `install.php` (Weg ueber Reinstallation, etwa nach einem git pull). Beide
 * Aufrufe sind gefahrlos wiederholbar: jedes Statement trifft nur Zeilen, die
 * noch den alten Wert tragen.
 *
 * **Bewusst nur SQL, keine Klassen dieses AddOns.** Bei einem Update ueber den
 * Installer laeuft diese Datei aus dem entpackten neuen Paket, waehrend noch die
 * *alten* Dateien installiert sind (`rex_install_package_update` inkludiert
 * `../.new.<addon>/update.php`). Der neue `vendor/`-Baum und die neuen Klassen
 * sind zu diesem Zeitpunkt nicht geladen -- was hier eine Klasse benutzt, faellt
 * genau dann um, wenn die Migration gebraucht wird.
 *
 * Anlass: Symfony AI 0.13 hat `dall-e-2` und `dall-e-3` aus dem OpenAI-Katalog
 * entfernt ("retired by OpenAI"). Ein Profil, das darauf zeigt, wird vom Katalog
 * abgewiesen, bevor ein Request entsteht -- die Fehlermeldung nennt nur den
 * Modellnamen und nicht den Grund. Dasselbe gilt fuer die gespeicherten Optionen:
 * `quality` kannte bei DALL-E die Werte standard und hd, die gpt-image-Modelle
 * nehmen auto/low/medium/high, und die Bildgroessen sind andere.
 */

declare(strict_types=1);

$table = rex::getTable('ai_profile');

// Das Modell selbst. gpt-image-1 ist das direkte Gegenstueck zu dall-e-3.
rex_sql::factory()->setQuery(
    'UPDATE ' . $table . " SET model = 'gpt-image-1'
     WHERE provider = 'openai' AND type = 'image_generation' AND model LIKE 'dall-e%'",
);

// Qualitaet: die Absicht bleibt erhalten, nur die Vokabeln wechseln.
rex_sql::factory()->setQuery(
    'UPDATE ' . $table . " SET image_quality = 'high'
     WHERE provider = 'openai' AND type = 'image_generation' AND image_quality = 'hd'",
);
rex_sql::factory()->setQuery(
    'UPDATE ' . $table . " SET image_quality = 'medium'
     WHERE provider = 'openai' AND type = 'image_generation' AND image_quality = 'standard'",
);

// Bildgroessen: die DALL-E-Formate auf das naechstliegende gpt-image-Format.
foreach ([
    '1792x1024' => '1536x1024',
    '1024x1792' => '1024x1536',
    '512x512' => '1024x1024',
    '256x256' => '1024x1024',
] as $old => $new) {
    $sql = rex_sql::factory();
    $sql->setQuery(
        'UPDATE ' . $table . ' SET image_size = :new
         WHERE provider = :provider AND type = :type AND image_size = :old',
        ['new' => $new, 'provider' => 'openai', 'type' => 'image_generation', 'old' => $old],
    );
}

// `image_style` wird nicht angetastet. Kein mitgelieferter Provider fuehrt das
// Feld mehr, und `Service::getProfileOptions()` sendet eine Option nur, wenn der
// Provider sie laut Registry anbietet -- ein alter Wert in der Spalte richtet
// also nichts an. Ihn zu loeschen wuerde nur die Einstellung eines Projekts
// wegwerfen, das sich per Extension Point einen Provider mit DALL-E-artigen
// Modellen registriert hat.
