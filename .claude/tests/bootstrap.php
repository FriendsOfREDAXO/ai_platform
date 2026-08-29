<?php

/**
 * Shared bootstrap for the addon's test scripts.
 *
 * Boots REDAXO core and all addons from the CLI, close enough to a real
 * request that translations and newly added classes both work. See the inline
 * comments for the two things a naive bootstrap gets wrong.
 */

declare(strict_types=1);

$htdocs = dirname(__DIR__, 6);

unset($REX);
$REX['REDAXO'] = true;
$REX['HTDOCS_PATH'] = $htdocs . '/';
$REX['BACKEND_FOLDER'] = 'redaxo';
$REX['LOAD_PAGE'] = false;
chdir($htdocs . '/redaxo');

require $htdocs . '/redaxo/src/core/boot.php';

// package.yml is cached in cache/core/packages.cache. Without dropping it, a
// newly declared page or permission stays invisible no matter how often the
// test runs — the same trap as the autoload cache below.
rex_file::delete(rex_path::coreCache('packages.cache'));

rex_addon::initialize();

// enlist() is what registers a package's lang/, fragments/, lib/ and vendor/
// directories. REDAXO calls it during a normal request; a CLI bootstrap that
// only calls boot() ends up with no addon translations and a stale class map,
// so every rex_i18n::msg() returns "[translate:key]".
foreach (rex::getPackageOrder() as $packageId) {
    rex_package::require($packageId)->enlist();
}

// Pick up classes added since the cache was last written. rex_autoload only
// rescans on its own during setup, on the console, in debug mode or for a
// logged-in admin — none of which hold here — and addon boot files reference
// classes inside callables, which PHP resolves eagerly against the map.
rex_autoload::reload(true);
rex_autoload::saveCache();

foreach (rex::getPackageOrder() as $packageId) {
    rex_package::require($packageId)->boot();
}

// Loads every registered lang directory for the configured locale.
rex_i18n::setLocale((string) rex::getProperty('lang', 'de_de'), false);

// Backend pages read the request through rex::getRequest(), which throws in
// CLI. A synthetic request lets page files be included and rendered in a test.
if (null === rex::getProperty('request')) {
    rex::setProperty('request', Symfony\Component\HttpFoundation\Request::create('/redaxo/index.php'));
}

// A run that starts with the feature switched off is not a run worth having.
// Three of the test files then abort with a raw stack trace from
// assertEnabled(), which reads like a code fault, and change-pages-test.php
// goes fully green because it switches the feature on for itself and restores
// the broken value afterwards. Worse, the safety net below snapshots whatever
// it finds and puts it back — so once the switch is off, every further run
// cements it. That state cost real time to diagnose: every REST route answers
// 404, which looks exactly like a routing bug.
//
// So refuse to start, and say how to fix it. A test suite that cannot test
// anything must not be able to report success.
if (1 !== (int) rex_config::get('ai_platform', 'changes_enabled', 0)) {
    fwrite(STDERR, "\n  ABORT: ai_platform change requests are switched off (changes_enabled = 0).\n"
        . "  Every REST route answers 404 in this state and most of this suite is meaningless.\n"
        . "  Switch it back on in the backend under »KI Platform > Einstellungen«, or:\n"
        . "    UPDATE " . rex::getTable('config') . " SET value = '1'\n"
        . "     WHERE namespace = 'ai_platform' AND `key` = 'changes_enabled';\n"
        . "  then delete redaxo/cache/core/config.cache.\n\n");
    exit(1);
}

// Tests flip configuration around, and a run that aborts halfway leaves it
// flipped. That is tolerable for a batch limit and not tolerable for
// `changes_allow_all_yform_tables`, which decides whether every table in the
// database is writable — a test must not be able to leave that open. Snapshot
// the security-relevant keys and restore them however the script ends.
$aiSafetyKeys = [
    'changes_enabled',
    'changes_allow_all_yform_tables',
    'changes_allowed_modules',
    'changes_allowed_yform_tables',
    'changes_stale_policy',
];

$aiSafetySnapshot = [];
foreach ($aiSafetyKeys as $aiSafetyKey) {
    $aiSafetySnapshot[$aiSafetyKey] = rex_config::get('ai_platform', $aiSafetyKey);
}

register_shutdown_function(static function () use ($aiSafetySnapshot): void {
    $restored = false;
    foreach ($aiSafetySnapshot as $key => $value) {
        if (rex_config::get('ai_platform', $key) !== $value) {
            rex_config::set('ai_platform', $key, $value);
            $restored = true;
        }
    }

    // The save() call is what makes this net a net. `rex_config::init()`
    // registers its own `save` shutdown handler during boot — that is, *before*
    // this one — and shutdown handlers run in registration order. So save()
    // has already run and cleared its changed-flag by the time this restore
    // writes, and without an explicit second save the restored values never
    // reach the database. This silently did nothing for a while, and was only
    // masked by every test also restoring its own keys before exiting; the one
    // test that forgot left a `warn` stale policy behind that made two other
    // test files fail with messages about approvals.
    if ($restored) {
        rex_config::save();
    }
});

/**
 * Tiny assertion helper shared by the test scripts.
 */
final class AiTestRunner
{
    private int $passed = 0;
    /** @var list<string> */
    private array $failures = [];

    public function __construct(private readonly string $title)
    {
        echo "\n=== {$title} ===\n";
    }

    public function section(string $name): void
    {
        echo "\n--- {$name} ---\n";
    }

    public function assert(bool $condition, string $message): bool
    {
        if ($condition) {
            echo "  OK    {$message}\n";
            ++$this->passed;

            return true;
        }

        echo "  FAIL  {$message}\n";
        $this->failures[] = $message;

        return false;
    }

    public function assertSame(mixed $expected, mixed $actual, string $message): bool
    {
        $ok = $expected === $actual;
        if (!$ok) {
            $message .= sprintf(' (expected %s, got %s)', var_export($expected, true), var_export($actual, true));
        }

        return $this->assert($ok, $message);
    }

    /**
     * Asserts that the callable throws, optionally with a message containing
     * the given needle.
     */
    public function assertThrows(callable $fn, string $message, ?string $needle = null): bool
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (null !== $needle && !str_contains($e->getMessage(), $needle)) {
                return $this->assert(false, $message . ' — threw, but message lacks "' . $needle . '": ' . $e->getMessage());
            }

            return $this->assert(true, $message);
        }

        return $this->assert(false, $message . ' — did not throw');
    }

    public function summary(): int
    {
        $failed = count($this->failures);
        echo "\n{$this->title}: {$this->passed} passed, {$failed} failed\n";

        if ($failed > 0) {
            foreach ($this->failures as $failure) {
                echo "  - {$failure}\n";
            }

            return 1;
        }

        return 0;
    }
}
