<?php

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Change\Agent\DescribeChangeTypesTool;
use FriendsOfRedaxo\AiPlatform\Change\Agent\ProposeChangeTool;
use FriendsOfRedaxo\AiPlatform\Change\Agent\ReadCurrentTool;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\Handler\ArticleHandler;
use FriendsOfRedaxo\AiPlatform\Change\Handler\CategoryHandler;
use FriendsOfRedaxo\AiPlatform\Change\Handler\MediaHandler;
use FriendsOfRedaxo\AiPlatform\Change\Handler\MetaHandler;
use FriendsOfRedaxo\AiPlatform\Change\Handler\SliceHandler;
use FriendsOfRedaxo\AiPlatform\Change\Handler\YformHandler;
use FriendsOfRedaxo\AiPlatform\Mcp\Context;
use FriendsOfRedaxo\AiPlatform\Mcp\Router;
use FriendsOfRedaxo\AiPlatform\Mcp\Tool;

$addon = rex_addon::get('ai_platform');

// Permissions for the change request pages. Two, because seeing and deciding are
// different jobs: a lead editor may approve, a stakeholder may only look.
//
// There used to be a third, `ai_changes[create]`, for the backend form that let
// a person file a request by hand. The form is gone — everything arrives through
// the REST API now — and a permission guarding nothing is worse than no
// permission, because it reads as if it still did something.
//
// Registered unconditionally, even while the feature is off — otherwise turning
// it back on would silently drop everyone's assignments.
rex_perm::register('ai_changes[]', null);
rex_perm::register('ai_changes[approve]', null, rex_perm::OPTIONS);

// Backend assets.
//
// Stamped on purpose: REDAXO serves these from assets/addons/ai_platform/, a copy
// made at install time, and browsers cache them hard. Without a stamp a user keeps
// the previous CSS/JS after an update and sees a half-broken UI whose cause is
// invisible. (The copy itself only refreshes on
// `console package:install ai_platform` — editing assets/ alone changes nothing.)
//
// The stamp is the **published copy's mtime**, not the addon version. The version
// is right for released updates and useless while working on the addon: it stays
// put across a dozen asset edits, so the reinstall refreshes the copy on disk and
// the browser keeps serving the old file from cache under an unchanged `?v=`. That
// looks exactly like a forgotten reinstall and wastes the time it takes to prove
// it was not one. The mtime moves precisely when the copy moves, which is the
// cache-busting condition itself. Falls back to the version when the copy is not
// there yet — the addon is enabled before it is installed.
if (rex::isBackend() && rex::getUser()) {
    $assetStamp = static function (string $file) use ($addon): string {
        $path = rex_path::addonAssets('ai_platform', $file);

        return is_file($path) ? (string) filemtime($path) : $addon->getVersion();
    };

    rex_view::addCssFile($addon->getAssetsUrl('styles.css') . '?v=' . $assetStamp('styles.css'));
    rex_view::addJsFile($addon->getAssetsUrl('profiles.js') . '?v=' . $assetStamp('profiles.js'));
    rex_view::addJsFile($addon->getAssetsUrl('changes.js') . '?v=' . $assetStamp('changes.js'));
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

// ---------------------------------------------------------------------------
// Change requests
// ---------------------------------------------------------------------------

// Built-in change handlers. Deliberately registered through the same extension
// point other addons use, so nothing here is privileged: a project can replace
// a built-in handler by registering its own under the same type.
rex_extension::register('AI_PLATFORM_CHANGE_HANDLERS', static function (rex_extension_point $ep) {
    $handlers = $ep->getSubject();

    $handlers['slice'] = new SliceHandler();
    $handlers['article'] = new ArticleHandler();
    $handlers['category'] = new CategoryHandler();
    $handlers['meta'] = new MetaHandler();
    $handlers['media'] = new MediaHandler();

    // YForm handler only makes sense when YForm is there.
    if (rex_addon::get('yform')->isAvailable()) {
        $handlers['yform'] = new YformHandler();
    }

    return $handlers;
});

// Change request tools for agents running inside REDAXO via
// Service::createAgent(). Note the extension point: AI_PLATFORM_AGENT_TOOLS
// carries Symfony AI tool objects and is a separate world from
// AI_PLATFORM_MCP_TOOLS, which serves external MCP clients. Change requests are
// deliberately not published over MCP — that surface belongs to whatever a
// project chooses to expose there.
rex_extension::register('AI_PLATFORM_AGENT_TOOLS', static function (rex_extension_point $ep) {
    $tools = $ep->getSubject();

    if (!ChangeService::getInstance()->isEnabled()) {
        return $tools;
    }

    $tools[] = new DescribeChangeTypesTool();
    $tools[] = new ReadCurrentTool();
    $tools[] = new ProposeChangeTool();

    return $tools;
});

// Menu entry for change requests: removed entirely when the feature is off,
// badged with the open count when it is on.
//
// The subject has to be returned — backend.php overwrites the page list with
// whatever this extension point hands back, so writing through
// rex_be_controller::setPages() inside the handler has no effect.
if (rex::isBackend() && rex::getUser()) {
    rex_extension::register('PAGES_PREPARED', static function (rex_extension_point $ep): array {
        return ChangeService::preparePages($ep->getSubject());
    });
}

// Cronjob that thins the JSON of long-decided change requests. Registered only
// when the cronjob addon is there — it stays an optional dependency, and without
// it nothing is thinned, which is the safe direction.
//
// The row is never deleted, only its payload and snapshots emptied. See the
// class for why that distinction is the whole point.
if (
    class_exists(rex_cronjob_manager::class)
    && ChangeService::getInstance()->isEnabled()
) {
    rex_cronjob_manager::registerType(FriendsOfRedaxo\AiPlatform\Cronjob\ThinChangeRequests::class);
}

// REST routes for change requests, registered with the api addon.
//
// This is the adapter for an agent that lives outside this REDAXO and holds
// nothing but a bearer token — the case the PHP API (needs server-side code),
// the agent tools (need an agent the CMS started) and the backend form (needs a
// session) cannot serve. Deliberately NOT on /mcp: that endpoint carries a
// project's own content and tools, not the CMS's internals.
//
// Three deliberate details:
//
// 1. Guarded by class_exists rather than a `requires_addons: api` entry. The
//    api addon stays optional — without it ai_platform works as before, minus
//    these routes.
// 2. loadRoutes() is called directly instead of registerRoutePackage().
//    RouteCollection::getRoutes() sets $packagesLoaded and never loads packages
//    again, so a package registered after any other addon read the route list
//    would be dropped without a word. registerRoute() writes into the static
//    array right away.
// 3. Nothing is registered while the master switch is off, so a disabled
//    feature has no HTTP surface at all — not even one returning errors.
if (
    class_exists(FriendsOfRedaxo\Api\RouteCollection::class)
    && ChangeService::getInstance()->isEnabled()
) {
    (new FriendsOfRedaxo\AiPlatform\Api\ChangeRoutes())->loadRoutes();
}
