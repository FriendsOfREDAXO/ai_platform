<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Language\Language;

use const PHP_VERSION;

/** Das eingebaute Tool `redaxo_status`. */
final class StatusTool
{
    /**
     * Returns the status of this REDAXO instance: URL, versions, number of articles, categories, media, users and
     * languages, and the installed addons with their versions.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'redaxo_status')]
    public static function status(): array
    {
        $count = static fn (string $sql): int => (int) (Sql::factory()->getArray($sql)[0]['cnt'] ?? 0);

        $addons = [];
        foreach (Addon::getAll() as $addon) {
            $addons[] = ['name' => $addon->name, 'package' => $addon->package, 'version' => $addon->getVersion()];
        }

        return [
            'redaxo_version' => Core::getVersion(),
            'php_version' => PHP_VERSION,
            'database_version' => Sql::factory()->getDbType() . ' ' . Sql::factory()->getDbVersion(),
            'server_url' => Url::absoluteBase(),
            'server_name' => Core::getProject()->instanceName,
            'languages' => array_values(array_map(static fn (Language $language): string => $language->name . ' (' . $language->code . ')', Language::getAll())),
            'articles' => $count('SELECT COUNT(*) AS cnt FROM ' . Core::TABLE_PREFIX . 'article'),
            'categories' => $count('SELECT COUNT(*) AS cnt FROM ' . Core::TABLE_PREFIX . 'category'),
            'media_files' => $count('SELECT COUNT(*) AS cnt FROM ' . Core::TABLE_PREFIX . 'media'),
            'users' => $count('SELECT COUNT(*) AS cnt FROM ' . Core::TABLE_PREFIX . 'user'),
            'mode' => Core::getMode()->name,
            'addons' => $addons,
        ];
    }
}
