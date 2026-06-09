<?php

declare(strict_types=1);

$htdocs = dirname(__DIR__, 6);
unset($REX);
$REX['REDAXO'] = true;
$REX['HTDOCS_PATH'] = $htdocs . '/';
$REX['BACKEND_FOLDER'] = 'redaxo';
$REX['LOAD_PAGE'] = false;
chdir($htdocs . '/redaxo');
require $htdocs . '/redaxo/src/core/boot.php';
rex_addon::initialize();
foreach (rex::getPackageOrder() as $packageId) {
    rex_package::require($packageId)->boot();
}

$addon = rex_addon::get('ai_platform');
$pages = $addon->getProperty('page');

function walk(array $node, string $prefix, string $indent = ''): void
{
    if (isset($node['title'])) {
        echo $indent . '- ' . $prefix . '  →  ' . ($node['title'] ?? '') . "\n";
    }
    if (isset($node['subpages']) && is_array($node['subpages'])) {
        foreach ($node['subpages'] as $key => $sub) {
            walk(is_array($sub) ? $sub : [], $prefix . '/' . $key, $indent . '  ');
        }
    }
}

walk($pages, 'ai_platform');

echo "\n=== rex_be_controller resolved sub-paths ===\n";
// Force pages registration (normally happens in backend.php which we don't run in CLI)
rex_be_controller::appendPackagePages();
foreach (['ai_platform/profiles', 'ai_platform/settings', 'ai_platform/mcp/settings', 'ai_platform/mcp/oauth-clients', 'ai_platform/mcp/scope-mapping', 'ai_platform/docs'] as $key) {
    $page = rex_be_controller::getPageObject($key);
    if (null === $page) {
        echo 'MISS ' . $key . " (page not registered)\n";
        continue;
    }
    $sub = $page->getSubPath();
    $exists = $sub && file_exists($sub);
    echo ($exists ? 'OK  ' : 'MISS') . ' ' . $key . '  →  ' . ($sub ? str_replace($addon->getPath(), '', $sub) : '(no subpath)') . "\n";
}
