<?php

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\OAuth\ScopeRegistry;

// Boot REDAXO + addons. Outputs JSON describing the seeded test fixtures.
//
// The core is located by walking up from this file rather than by counting
// directories: where it sits depends on the layout. A standard install keeps it
// under <htdocs>/redaxo, while a project with its own path provider puts src/
// straight at the project root -- counting levels only ever fits one of them.
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
    // Project with a custom path provider ($REX['PATH_PROVIDER'], a core
    // feature). The class is conventionally named app_path_provider and extends
    // rex_path_default_provider; without it the paths below stay the defaults.
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
    rex_package::require($packageId)->boot();
}

$cmd = $argv[1] ?? 'seed';

$loginPrefix = 'ai_platform_oauth_test_';
$groupName = 'AI Platform OAuth Test';
$password = 'TestPass!' . bin2hex(random_bytes(6));

if ('seed' === $cmd) {
    // Leftovers from a run that ended early: they block the unique columns and
    // would make this seed fail for a reason that has nothing to do with the test.
    rex_sql::factory()->setQuery(
        'DELETE FROM ' . rex::getTable('ycom_user') . ' WHERE login LIKE ?',
        [$loginPrefix . '%'],
    );

    // Create a group
    $existingGroups = rex_sql::factory();
    $existingGroups->setQuery('SELECT id FROM ' . rex::getTable('ycom_group') . ' WHERE name = ?', [$groupName]);
    if (0 === $existingGroups->getRows()) {
        $g = rex_sql::factory();
        $g->setTable(rex::getTable('ycom_group'));
        $g->setValue('name', $groupName);
        $g->insert();
        $groupId = (int) $g->getLastId();
    } else {
        $groupId = (int) $existingGroups->getValue('id');
    }

    // Map group to scopes
    ScopeRegistry::setScopesForGroup($groupId, [
        ScopeRegistry::SCOPE_TOOLS_READ,
        ScopeRegistry::SCOPE_TOOLS_CALL,
    ]);

    // Create the user. Login field on this instance is "email" (default).
    $loginEmail = $loginPrefix . bin2hex(random_bytes(4)) . '@example.test';

    $u = rex_sql::factory();
    $u->setTable(rex::getTable('ycom_user'));
    $u->setValue('login', $loginEmail);
    $u->setValue('email', $loginEmail);
    $u->setValue('name', 'OAuth Test User');
    $u->setValue('password', rex_login::passwordHash($password));
    $u->setValue('status', 1);
    $u->setValue('ycom_groups', (string) $groupId);
    // Unique column: a second row with the empty default collides, and a run that
    // ended early leaves exactly such a row behind -- the next seed then dies with
    // "Duplicate entry '' for key 'activation_key'" and every later assertion fails
    // for want of a user, which reads like a broken endpoint.
    $u->setValue('activation_key', bin2hex(random_bytes(16)));
    $u->insert();
    $userId = (int) $u->getLastId();

    echo json_encode([
        'user_id' => $userId,
        'group_id' => $groupId,
        'login' => $loginEmail,
        'password' => $password,
    ], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

// A second user, deliberately in no group at all.
//
// Scopes reach a user through groups, so this one can never hold any -- which is
// the normal state of an installation that does not use YCom groups, and the case
// that broke `/oauth/authorize` with an SQL error (`... IN ()`) while every test
// stayed green, because the seeded user above always has a group.
if ('seed-nogroup' === $cmd) {
    $loginEmail = $loginPrefix . 'nogroup_' . bin2hex(random_bytes(4)) . '@example.test';

    $u = rex_sql::factory();
    $u->setTable(rex::getTable('ycom_user'));
    $u->setValue('login', $loginEmail);
    $u->setValue('email', $loginEmail);
    $u->setValue('name', 'OAuth Test User Without Group');
    $u->setValue('password', rex_login::passwordHash($password));
    $u->setValue('status', 1);
    $u->setValue('ycom_groups', '');
    $u->setValue('activation_key', bin2hex(random_bytes(16)));
    $u->insert();

    echo json_encode([
        'user_id' => (int) $u->getLastId(),
        'login' => $loginEmail,
        'password' => $password,
    ], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

// Signing in is YCom's business now, so the test drives YCom's own login form.
// This reports where that form lives, and the id of an article that is guaranteed
// not to exist -- the endpoint has to answer differently for a login page that is
// not configured, and that case has to be provable without breaking the site.
if ('login-article' === $cmd) {
    $articleId = (int) rex_ycom_config::get('article_id_login');
    $maxSql = rex_sql::factory();
    $maxSql->setQuery('SELECT MAX(id) AS m FROM ' . rex::getTable('article'));

    echo json_encode([
        'id' => $articleId,
        'exists' => null !== rex_article::get($articleId),
        // Relative on purpose: rex_url::frontend() resolves to a filesystem path in
        // CLI, and the caller knows the base URL it is testing against anyway.
        'path' => $articleId > 0 ? '/index.php?article_id=' . $articleId . '&clang=' . rex_clang::getStartId() : '',
        'missing' => ((int) $maxSql->getValue('m')) + 1000,
    ], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

// Points YCom at another login article and reports the id it replaced, so the
// caller can put it back. This writes into *YCom's* plugin config, which is the
// single place the setting lives -- this addon deliberately has no copy of it.
// A test that left this pointing at a missing article would break every frontend
// login on the installation, so the caller restores it in a trap.
if ('set-login-article' === $cmd) {
    $plugin = rex_plugin::get('ycom', 'auth');
    $previous = (int) $plugin->getConfig('article_id_login');
    $plugin->setConfig('article_id_login', (int) ($argv[2] ?? 0));

    echo json_encode(['previous' => $previous], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

if ('cleanup' === $cmd) {
    $userIds = (string) ($argv[2] ?? '');
    $groupIds = (string) ($argv[3] ?? '');
    $sql = rex_sql::factory();
    if ('' !== $userIds) {
        $sql->setQuery('DELETE FROM ' . rex::getTable('ycom_user') . ' WHERE id IN (' . $userIds . ')');
    }
    if ('' !== $groupIds) {
        $sql->setQuery('DELETE FROM ' . rex::getTable('ycom_group') . ' WHERE id IN (' . $groupIds . ')');
        ScopeRegistry::setScopesForGroup((int) $groupIds, []);
    }
    // Wipe all DCR clients + their tokens to keep the DB clean
    $sql->setQuery("DELETE FROM " . rex::getTable('ai_oauth_token') . " WHERE client_id IN (SELECT client_id FROM " . rex::getTable('ai_oauth_client') . " WHERE created_by_dcr = 1 OR client_name = 'AI Platform OAuth Test')");
    $sql->setQuery("DELETE FROM " . rex::getTable('ai_oauth_authorization_code') . " WHERE client_id IN (SELECT client_id FROM " . rex::getTable('ai_oauth_client') . " WHERE created_by_dcr = 1 OR client_name = 'AI Platform OAuth Test')");
    $sql->setQuery("DELETE FROM " . rex::getTable('ai_oauth_client') . " WHERE created_by_dcr = 1 OR client_name = 'AI Platform OAuth Test'");
    echo "cleaned up\n";
    exit(0);
}

fwrite(STDERR, "unknown command: $cmd\n");
exit(1);
