<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform;

use RuntimeException;

/** Fehler in der Konfiguration eines Profils oder Anbieters (unbekannter Anbieter, fehlender Schlüssel …). */
final class AiPlatformException extends RuntimeException {}
