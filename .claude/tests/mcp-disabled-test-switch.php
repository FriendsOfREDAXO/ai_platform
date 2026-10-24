<?php

declare(strict_types=1);

// Helper for mcp-disabled-test.sh. Commands:
//   get                  print mcp_enabled (0/1)
//   set <0|1>            write mcp_enabled
//   clients <name>       print the number of OAuth clients with that client_name
//   cleanup <name>       delete OAuth clients with that client_name
//
// Same core lookup as oauth-authorize-test-seed.php: walk up to src/core/boot.php,
// because a standard install and a project with its own path provider differ.
$base = __DIR__;
while (!is_file($base . '/src/core/boot.php')) {
    $parent = dirname($base);
    if ($parent === $base) {
        fwrite(STDERR, 'Could not locate src/core/boot.php above ' . __DIR__ . "\n");
        exit(1);
    }
    $base = $parent;
}

unset($REX);
$REX['REDAXO'] = true;
$REX['BACKEND_FOLDER'] = 'redaxo';
$REX['LOAD_PAGE'] = false;

if (is_file($base . '/src/path_provider.php')) {
    $REX['HTDOCS_PATH'] = '../';
    require $base . '/src/path_provider.php';
    if (class_exists('app_path_provider')) {
        $REX['PATH_PROVIDER'] = new app_path_provider();
    }
} else {
    $REX['HTDOCS_PATH'] = dirname($base) . '/';
}

chdir($base);
require $base . '/src/core/boot.php';
rex_addon::initialize();
foreach (rex::getPackageOrder() as $packageId) {
    rex_package::require($packageId)->enlist();
}

$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';

switch ($cmd) {
    case 'get':
        echo (int) rex_config::get('ai_platform', 'mcp_enabled', 0), "\n";
        break;
    case 'set':
        // rex_config::save() also refreshes the config cache the web requests read
        rex_config::set('ai_platform', 'mcp_enabled', (int) $arg);
        rex_config::save();
        break;
    case 'clients':
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT COUNT(*) AS c FROM ' . rex::getTable('ai_oauth_client') . ' WHERE client_name = ?', [$arg]);
        echo (int) $sql->getValue('c'), "\n";
        break;
    case 'cleanup':
        rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('ai_oauth_client') . ' WHERE client_name = ?', [$arg]);
        break;
    default:
        fwrite(STDERR, "usage: get | set <0|1> | clients <name> | cleanup <name>\n");
        exit(1);
}
