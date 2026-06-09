<?php

declare(strict_types=1);

// Boot REDAXO + addons. Outputs JSON describing the seeded test fixtures.
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

$cmd = $argv[1] ?? 'seed';

$loginPrefix = 'ai_platform_oauth_test_';
$groupName = 'AI Platform OAuth Test';
$password = 'TestPass!' . bin2hex(random_bytes(6));

if ('seed' === $cmd) {
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
    rex_ai_oauth_scope_registry::setScopesForGroup($groupId, [
        rex_ai_oauth_scope_registry::SCOPE_TOOLS_READ,
        rex_ai_oauth_scope_registry::SCOPE_TOOLS_CALL,
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

if ('cleanup' === $cmd) {
    $userIds = (string) ($argv[2] ?? '');
    $groupIds = (string) ($argv[3] ?? '');
    $sql = rex_sql::factory();
    if ('' !== $userIds) {
        $sql->setQuery('DELETE FROM ' . rex::getTable('ycom_user') . ' WHERE id IN (' . $userIds . ')');
    }
    if ('' !== $groupIds) {
        $sql->setQuery('DELETE FROM ' . rex::getTable('ycom_group') . ' WHERE id IN (' . $groupIds . ')');
        rex_ai_oauth_scope_registry::setScopesForGroup((int) $groupIds, []);
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
