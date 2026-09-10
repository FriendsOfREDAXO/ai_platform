<?php

/**
 * Update-Skript des AddOns.
 *
 * Wird vom install-AddOn beim Aktualisieren ueber den AddOn-Installer
 * ausgefuehrt, bevor die neuen Dateien die alten ersetzen. Zu diesem Zeitpunkt
 * gilt: **keine Klassen dieses AddOns benutzen** -- der neue `vendor/`-Baum und
 * die neuen Klassen sind noch nicht geladen. Siehe den Kopf der eingebundenen
 * Datei.
 */

declare(strict_types=1);

require __DIR__ . '/update-image-models.php';
