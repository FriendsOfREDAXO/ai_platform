<?php

/**
 * `update.php` — the path a running installation actually takes.
 *
 * Why this exists: REDAXO does **not** run `install.php` when an addon is
 * updated through the AddOn installer. `rex_install_package_update::run()`
 * includes `update.php`, then moves the new files into place, copies the assets
 * and writes the version — there is no `install()` call anywhere on that route.
 * So every table and column a new version needs has to be reachable from
 * `update.php`, and for a long time it was not: the change-request tables
 * arrived after 1.0.0-beta3 and lived only in `install.php`. A project updating
 * from beta3 got the new code and none of the new tables, and the inbox died
 * with `Table 'rex_ai_change_request' doesn't exist`.
 *
 * The test therefore does what the installer does — take a table away, run
 * `update.php`, see whether it came back — rather than asserting that one file
 * includes another, which would still pass if the include stopped working.
 *
 * The table is **renamed, not dropped**, and put back in a `finally` plus a
 * shutdown handler: this runs against the real database, and a test that can
 * lose a project's change requests has no business existing.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$t = new AiTestRunner('Update script');

$addonPath = dirname(__DIR__, 2);

/** Every table install.php is responsible for. */
$tables = [
    'ai_profile',
    'ai_oauth_client',
    'ai_oauth_authorization_code',
    'ai_oauth_token',
    'ai_scope_mapping',
    'ai_change_request',
    'ai_changeset',
    'ai_change_upload',
];

$exists = static function (string $table): bool {
    $sql = rex_sql::factory();
    $sql->setQuery('SHOW TABLES LIKE ' . $sql->escape($table));

    return $sql->getRows() > 0;
};

// ---------------------------------------------------------------------------
$t->section('update.php may not reach for anything that is not loaded yet');

// When the installer includes update.php, the *old* files are still installed:
// the new vendor/ tree is not loaded and the new classes of this addon do not
// exist for the autoloader. Touching one of them fails exactly when the
// migration is needed, so the constraint is pinned here rather than in a comment.
$updateSource = (string) file_get_contents($addonPath . '/update.php');
$installSource = (string) file_get_contents($addonPath . '/install.php');

$t->assert(
    !preg_match('#^\s*(?!\s*\*)\S.*FriendsOfRedaxo\\\\#m', str_replace('*', '* ', $updateSource)),
    'update.php references no addon class outside comments',
);
$t->assert(
    !str_contains($installSource, 'rex_addon::get'),
    'install.php resolves no paths through rex_addon, which would point at the old directory',
);

// ---------------------------------------------------------------------------
$t->section('A table that went missing is restored');

$missing = rex::getTable('ai_changeset');
$parked = $missing . '_update_test';
$restored = false;

$restore = static function () use ($missing, $parked, &$restored): void {
    if ($restored) {
        return;
    }
    $sql = rex_sql::factory();
    $sql->setQuery('SHOW TABLES LIKE ' . $sql->escape($parked));
    if (0 === $sql->getRows()) {
        return;
    }
    // Drop whatever update.php just created, then put the real table back.
    rex_sql::factory()->setQuery('DROP TABLE IF EXISTS ' . $missing);
    rex_sql::factory()->setQuery('RENAME TABLE ' . $parked . ' TO ' . $missing);
    $restored = true;
};

// Belt and braces: a fatal error anywhere below must not leave the table parked.
register_shutdown_function($restore);

try {
    $t->assert($exists($missing), 'the table is there to begin with');

    rex_sql::factory()->setQuery('RENAME TABLE ' . $missing . ' TO ' . $parked);
    $t->assert(!$exists($missing), 'and taken away for the test');

    // Exactly what the installer does with the file it unpacked.
    require $addonPath . '/update.php';

    $recreated = $exists($missing);
    $t->assert($recreated, 'update.php recreated it');

    // Guarded: without the table, SHOW COLUMNS throws, and the run would end in a
    // stack trace instead of naming which assertion failed. A red run has to be
    // readable, or it cannot be told apart from a broken test.
    if ($recreated) {
        $columns = rex_sql::factory();
        $columns->setQuery('SHOW COLUMNS FROM ' . $missing);
        $names = array_column($columns->getArray(), 'Field');
        $t->assert(in_array('id', $names, true), 'and it carries its columns, not just a name');
        $t->assert(in_array('status', $names, true), 'including the one the inbox groups by');
    } else {
        $t->assert(false, 'and it carries its columns, not just a name');
        $t->assert(false, 'including the one the inbox groups by');
    }
} finally {
    $restore();
}

$t->assert($exists($missing), 'the original table is back');

// ---------------------------------------------------------------------------
$t->section('Every table the addon owns survives the update path');

foreach ($tables as $table) {
    $t->assert($exists(rex::getTable($table)), rex::getTable($table) . ' exists');
}

exit($t->summary());
