<?php

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Mcp\Context;
use FriendsOfRedaxo\AiPlatform\Mcp\Router;
use FriendsOfRedaxo\AiPlatform\Mcp\Tool;

$addon = rex_addon::get('ai_platform');

// Backend assets
if (rex::isBackend() && rex::getUser()) {
    rex_view::addJsFile($addon->getAssetsUrl('profiles.js'));
}

// Route /mcp, /.well-known/oauth-*, /oauth/* before structure/yrewrite
// take over the frontend request.
if (!rex::isBackend()) {
    rex_extension::register('PACKAGES_INCLUDED', static function (): void {
        Router::dispatch();
    });
}

// Register built-in MCP tools
rex_extension::register('AI_PLATFORM_MCP_TOOLS', static function (rex_extension_point $ep) {
    $tools = $ep->getSubject();

    $tools['redaxo_status'] = new Tool(
        name: 'redaxo_status',
        description: 'Returns the current status of this REDAXO CMS instance: URL, versions, number of articles, categories, media, users, languages, and a list of installed addons with their versions.',
        inputSchema: [
            'type' => 'object',
            'properties' => new \stdClass(),
        ],
        handler: static function (array $arguments, Context $context): string {
            $sql = rex_sql::factory();

            // Article count
            $sql->setQuery('SELECT COUNT(*) as cnt FROM ' . rex::getTable('article'));
            $articleCount = (int) $sql->getValue('cnt');

            // Category count
            $sql->setQuery('SELECT COUNT(*) as cnt FROM ' . rex::getTable('article') . ' WHERE startarticle = 1');
            $categoryCount = (int) $sql->getValue('cnt');

            // Media count
            $sql->setQuery('SELECT COUNT(*) as cnt FROM ' . rex::getTable('media'));
            $mediaCount = (int) $sql->getValue('cnt');

            // User count
            $sql->setQuery('SELECT COUNT(*) as cnt FROM ' . rex::getTable('user'));
            $userCount = (int) $sql->getValue('cnt');

            // Languages
            $languages = [];
            foreach (rex_clang::getAll() as $clang) {
                $languages[] = $clang->getName() . ' (' . $clang->getCode() . ')';
            }

            // Addons
            $addons = [];
            foreach (rex_addon::getAvailableAddons() as $addon) {
                $addonInfo = [
                    'name' => $addon->getName(),
                    'version' => $addon->getVersion(),
                ];
                $plugins = [];
                foreach ($addon->getAvailablePlugins() as $plugin) {
                    $plugins[] = $plugin->getName() . ' ' . $plugin->getVersion();
                }
                if ($plugins) {
                    $addonInfo['plugins'] = $plugins;
                }
                $addons[] = $addonInfo;
            }

            // DB version
            $sql->setQuery('SELECT VERSION() as v');
            $dbVersion = $sql->getValue('v');

            $status = [
                'redaxo_version' => rex::getVersion(),
                'php_version' => PHP_VERSION,
                'database_version' => $dbVersion,
                'server_url' => rex::getServer(),
                'server_name' => rex::getServerName(),
                'languages' => $languages,
                'default_language' => rex_clang::get(rex_clang::getStartId())?->getName(),
                'articles' => $articleCount,
                'categories' => $categoryCount,
                'media_files' => $mediaCount,
                'users' => $userCount,
                'debug_mode' => rex::isDebugMode(),
                'safe_mode' => rex::isSafeMode(),
                'addons' => $addons,
            ];

            return json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        },
        public: true,
    );

    return $tools;
});
