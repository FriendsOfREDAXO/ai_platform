<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Core;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Filesystem\Url;

/**
 * Baut den MCP-Server einer Anfrage: Name und Version der Instanz, Hinweise für das Modell, die Tools aus
 * {@see ToolRegistry} und ein Sitzungsspeicher in Dateien.
 *
 * Dateien, weil jede Anfrage ein eigener PHP-Prozess ist: der Speicher im Arbeitsspeicher, die Vorgabe des SDK, wäre
 * bei der nächsten Anfrage leer, und der Client bekäme nach dem `initialize` auf jede Anfrage „Session not found".
 */
final class McpServer
{
    public static function build(): Server
    {
        $addon = Addon::require('ai_platform');

        $builder = Server::builder()
            ->setServerInfo(
                Core::getProject()->instanceName,
                $addon->getVersion(),
                'REDAXO ' . Core::getVersion() . ' – ' . Url::absoluteBase(),
            )
            ->setSession(new FileSessionStore(Path::addonCache('ai_platform', 'mcp-sessions')))
            ->setLogger(new McpLogger());

        $instructions = trim((string) $addon->getConfig('mcp_instructions', ''));
        if ('' !== $instructions) {
            $builder->setInstructions($instructions);
        }

        foreach (ToolRegistry::all() as $tool) {
            $builder->addTool(...$tool);
        }

        return $builder->build();
    }
}
