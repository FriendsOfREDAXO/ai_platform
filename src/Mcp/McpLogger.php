<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Redaxo\Core\Log\Logger;
use Stringable;
use Throwable;

use function in_array;

/**
 * Reicht Warnungen und Fehler des MCP-SDK an das Systemlog von REDAXO weiter.
 *
 * Ohne Logger sieht man von einem scheiternden Tool nur „Error while executing tool" beim Client; die Ursache
 * verschluckt das SDK. Debug- und Info-Meldungen bleiben draußen, das SDK schreibt davon mehrere je Anfrage.
 */
final class McpLogger extends AbstractLogger
{
    private const array LEVELS = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            return;
        }

        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            Logger::logException($exception);

            return;
        }

        Logger::factory()->log($level, 'MCP: ' . $message, array_filter($context, is_scalar(...)));
    }
}
