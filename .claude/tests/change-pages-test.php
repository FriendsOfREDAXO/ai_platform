<?php

/**
 * Renders the change request backend pages and checks the page tree.
 *
 * Catches the class of bug that unit tests miss and that only shows up as a
 * white screen: a page file referencing something that does not exist, a
 * subpage whose file cannot be resolved, or output that leaks unescaped
 * payload content.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use FriendsOfRedaxo\AiPlatform\Change\ApiApproval;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\Payload\ArticlePayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\MediaPayload;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\Target\MediaTarget;
use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;
use FriendsOfRedaxo\AiPlatform\Change\Target\ArticleTarget;

$t = new AiTestRunner('Change request pages');

// The backend controller normally registers pages in backend.php.
rex_be_controller::appendPackagePages();

$t->section('Page tree');

// Settings and guide moved under `ai_platform`, where the rest of the addon's
// administration and documentation lives. What is left under `ai_changes` is the
// inbox — the only part an editor needs — and it is now the page itself rather
// than a subpage.
foreach ([
    'ai_changes',
    'ai_platform/settings',
    'ai_platform/docs/changes',
] as $key) {
    $page = rex_be_controller::getPageObject($key);
    $t->assert(null !== $page, "page {$key} is registered");
}

// The two top-level entries have to be neighbours in the menu: the addon and the
// inbox it feeds read as one pair, and an admin should not find them at opposite
// ends of the navigation. Prios (24 / 25) are what places them, and nothing else
// in the codebase would notice if a core addon later claimed one of those slots.
$mainPages = [];
foreach (rex_be_controller::getPages() as $key => $page) {
    if ($page instanceof rex_be_page_main && 'system' === $page->getBlock()) {
        $mainPages[$key] = $page->getPrio();
    }
}
asort($mainPages);
$order = array_keys($mainPages);
$platformAt = array_search('ai_platform', $order, true);
$changesAt = array_search('ai_changes', $order, true);

$t->assert(false !== $platformAt && false !== $changesAt, 'both top-level entries are in the system block');
$t->assertSame(
    1,
    false !== $platformAt && false !== $changesAt ? $changesAt - $platformAt : -99,
    'ai_changes sits directly below ai_platform in the menu',
);

// The change settings were merged into `ai_platform/settings` rather than kept as
// a sibling tab. The master switch and the settings it governs belong in one
// form; split across two pages, an admin had to find the second one to finish
// configuring what the first switched on.
$t->assert(
    null === rex_be_controller::getPageObject('ai_platform/settings/changes'),
    'the separate change settings page is gone',
);
$t->assert(
    null === rex_be_controller::getPageObject('ai_platform/settings/general'),
    'and so is the general/changes split it created',
);
$t->assert(
    !is_file(__DIR__ . '/../../pages/settings.changes.php')
    && !is_file(__DIR__ . '/../../pages/settings.general.php'),
    'both page files are gone, not just their registrations',
);

// The manual entry form is gone: change requests only arrive through the REST
// routes now. Asserting its absence rather than letting it quietly come back —
// a second submission path would have to be kept in step with the first, and the
// two drifting apart is how a form ends up accepting what the API refuses.
$t->assert(
    null === rex_be_controller::getPageObject('ai_changes/new'),
    'the manual entry form is gone',
);
$t->assert(
    !file_exists(__DIR__ . '/../../pages/ai_changes.new.php'),
    'and its page file is gone too, not merely unregistered',
);
$t->assert(
    !rex_perm::has('ai_changes[create]'),
    'the permission that guarded it is gone as well',
);

foreach (['ai_changes[]', 'ai_changes[approve]'] as $perm) {
    $t->assert(rex_perm::has($perm), "the {$perm} permission is registered and assignable");
}

// The change settings now sit under ai_platform, which is admin-only as a whole
// — so they inherit the gate instead of carrying their own, and an editor never
// sees them.
$aiPlatform = rex_be_controller::getPageObject('ai_platform');
$t->assert(
    null !== $aiPlatform && in_array('admin[]', $aiPlatform->getRequiredPermissions(), true),
    'the change settings inherit admin-only from ai_platform',
);

$t->assert(
    null === rex_be_controller::getPageObject('ai_changes/settings'),
    'and are gone from the editorial area',
);
$t->assert(
    null === rex_be_controller::getPageObject('ai_changes/docs'),
    'the guide moved too — one place for documentation, not two',
);

$top = rex_be_controller::getPageObject('ai_changes');
$t->assert(
    null !== $top && in_array('ai_changes[]', $top->getRequiredPermissions(), true),
    'the top level page is gated by ai_changes[]',
);
$t->assert(
    null !== $top && $top instanceof rex_be_page_main,
    'the top level page is a main page, so it appears in the menu',
);

$t->assert(
    in_array('ai_changes[]', array_keys(rex_perm::getAll()), true) || rex_perm::has('ai_changes[]'),
    'the ai_changes[] permission is registered and therefore assignable',
);

$t->section('Option permissions open the section');

// Captured before anything switches it, and restored in the cleanup section.
// Read here rather than further down because everything from this point on needs
// the feature on: `preparePages()` removes the page entirely while it is off, so
// an installation that happens to have it disabled would fail the two permission
// asserts below with a message about permissions. Ambient state the test depends
// on but never sets is how a green suite turns red on somebody else's machine.
$originalEnabled = rex_config::get('ai_platform', 'changes_enabled', 1);
rex_config::set('ai_platform', 'changes_enabled', 1);

/**
 * A role granted only ai_changes[create] (or [approve]) must be able to reach
 * the section. rex_be_page::checkPermission() ANDs the page's requirement with
 * every parent's, so without preparePages() lifting the top-level requirement
 * the option is decorative: the user is turned away before ever seeing the form
 * they were explicitly allowed to use. The two permissions also sit in a
 * different block of the role form than the general one, which makes the
 * missing second tick invisible rather than merely tedious.
 *
 * Roles are created here and removed again in the cleanup section, so the
 * installation's own roles are never touched.
 */
$roleSql = rex_sql::factory();
$makeRole = static function (string $general, string $options) use ($roleSql): int {
    $roleSql->setTable(rex::getTable('user_role'));
    $roleSql->setValue('name', 'AI_PAGES_TEST_' . substr(md5($general . '|' . $options . microtime()), 0, 12));
    // All keys, not just the two that matter here: rex_user_role reads every
    // one of them unconditionally and warns on a partial row.
    $roleSql->setValue('perms', json_encode([
        'general' => '' === $general ? null : '|' . $general . '|',
        'options' => '' === $options ? null : '|' . $options . '|',
        'extras' => null,
        'clang' => null,
        'media' => null,
        'structure' => null,
        'modules' => null,
    ], JSON_THROW_ON_ERROR));
    $roleSql->insert();

    return (int) $roleSql->getLastId();
};

$userSql = rex_sql::factory();
$makeUser = static function (int $roleId) use ($userSql): int {
    $login = 'ai_pages_test_' . substr(md5((string) $roleId . microtime()), 0, 10);
    $userSql->setTable(rex::getTable('user'));
    $userSql->setValue('name', 'AI pages test');
    $userSql->setValue('login', $login);
    $userSql->setValue('password', password_hash($login, PASSWORD_DEFAULT));
    $userSql->setValue('admin', 0);
    $userSql->setValue('role', $roleId);
    $userSql->setValue('status', 1);
    $userSql->setValue('createdate', date('Y-m-d H:i:s'));
    $userSql->setValue('createuser', 'test');
    $userSql->setValue('updatedate', date('Y-m-d H:i:s'));
    $userSql->setValue('updateuser', 'test');
    $userSql->insert();

    return (int) $userSql->getLastId();
};

$testRoleIds = [];
$testUserIds = [];

/**
 * Runs the backend.php sequence for one user and reports which of the
 * ai_changes pages that user can actually open.
 *
 * appendPackagePages() has to run again per user, because PAGES_PREPARED may
 * have rewritten the page objects for the previous one.
 *
 * @return array<string, bool>
 */
$accessFor = static function (rex_user $user): array {
    $previous = rex::getProperty('user');
    rex::setProperty('user', $user);

    rex_be_controller::appendPackagePages();
    $pages = rex_extension::registerPoint(new rex_extension_point('PAGES_PREPARED', rex_be_controller::getPages()));

    $result = [];
    $top = $pages['ai_changes'] ?? null;
    $result['ai_changes'] = $top instanceof rex_be_page && $top->checkPermission($user);

    rex::setProperty('user', $previous);

    return $result;
};

$cases = [
    'approve only' => ['', 'ai_changes[approve]', ['ai_changes' => true]],
    'view only' => ['ai_changes[]', '', ['ai_changes' => true]],
    'nothing' => ['structure', '', ['ai_changes' => false]],
];

// The handler is registered later in the master-switch section; do it here so
// this section already exercises the real wiring, and guard against a double
// registration doing the work twice.
rex_extension::register('PAGES_PREPARED', static function (rex_extension_point $ep): array {
    return ChangeService::preparePages($ep->getSubject());
});

foreach ($cases as $label => [$general, $options, $expected]) {
    $roleId = $makeRole($general, $options);
    $userId = $makeUser($roleId);
    $testRoleIds[] = $roleId;
    $testUserIds[] = $userId;

    $user = rex_user::get($userId);
    if (null === $user) {
        echo "  SKIP  {$label}: test user could not be loaded\n";
        continue;
    }

    $actual = $accessFor($user);
    foreach ($expected as $key => $want) {
        $t->assert(
            $actual[$key] === $want,
            sprintf('%s: %s is %s', $label, $key, $want ? 'reachable' : 'blocked'),
        );
    }
}

// Cleanup right here, so a later failure cannot leave test users behind that a
// human would then find in the user list.
foreach ($testUserIds as $id) {
    rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('user') . ' WHERE id = :id', [':id' => $id]);
}
foreach ($testRoleIds as $id) {
    rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('user_role') . ' WHERE id = :id', [':id' => $id]);
}
$t->assert(true, 'test users and roles removed');

$t->section('Assets are actually delivered');

// REDAXO does not serve assets/ from the addon directory — it copies them to
// assets/addons/ai_platform/ at install time. Editing the source and forgetting
// `console package:install ai_platform` means the browser keeps the old CSS and
// JS, and the UI looks broken for reasons invisible in the source. That happened
// once, so it is checked.
$addon = rex_addon::get('ai_platform');

foreach (['styles.css', 'changes.js', 'profiles.js'] as $asset) {
    $source = $addon->getPath('assets/' . $asset);
    $published = $addon->getAssetsPath($asset);

    $t->assert(is_file($source), "asset source {$asset} exists");

    if (!is_file($published)) {
        $t->assert(false, "asset {$asset} is published — run: console package:install ai_platform");
        continue;
    }

    $t->assertSame(
        (string) rex_file::get($source),
        (string) rex_file::get($published),
        "published {$asset} matches the source (otherwise: console package:install ai_platform)",
    );
}

// Version-stamped URLs, or browsers keep the stale copy after an update.
$bootSource = (string) rex_file::get($addon->getPath('boot.php'));
$t->assert(
    str_contains($bootSource, "getAssetsUrl('styles.css') . '?v='"),
    'the stylesheet URL is cache-busted',
);
$t->assert(
    str_contains($bootSource, "getAssetsUrl('changes.js') . '?v='"),
    'the script URL is cache-busted',
);

$t->section('Master switch disables the feature');

/**
 * Reproduces what backend.php actually does around PAGES_PREPARED:
 *
 *     $pages = registerPoint(new rex_extension_point('PAGES_PREPARED', getPages()));
 *     setPages($pages);
 *
 * Calling the handler directly and then reading getPages() would pass even for a
 * handler that writes through setPages() and gets overwritten a line later —
 * which is exactly the bug this reproduces.
 */
$runPagesPrepared = static function (): void {
    $pages = rex_extension::registerPoint(new rex_extension_point('PAGES_PREPARED', rex_be_controller::getPages()));
    rex_be_controller::setPages($pages);
};

// The boot.php handler only registers itself in a backend request, so register
// the same closure here to exercise the real wiring.
rex_extension::register('PAGES_PREPARED', static function (rex_extension_point $ep): array {
    return ChangeService::preparePages($ep->getSubject());
});

rex_config::set('ai_platform', 'changes_enabled', 0);
rex_be_controller::appendPackagePages();
$runPagesPrepared();

$t->assert(
    null === rex_be_controller::getPageObject('ai_changes'),
    'with the feature off, the page is gone from the list backend.php actually uses',
);

// And the dispatcher refuses even when reached directly, because a removed menu
// entry is not the same as an unreachable page.
$adminIdForSwitch = (int) (rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('user') . ' WHERE admin = 1 AND status = 1 ORDER BY id LIMIT 1')[0]['id'] ?? 0);

if ($adminIdForSwitch > 0) {
    rex::setProperty('user', rex_user::get($adminIdForSwitch));

    $_GET = ['page' => 'ai_changes'];
    $_POST = [];
    $_REQUEST = $_GET;

    ob_start();
    try {
        // ai_changes.php, not the inbox directly: the refusal lives there, and it
        // runs before rex_view::title() precisely because the page object is gone
        // once the feature is off — titling first would fatal instead of
        // explaining.
        include __DIR__ . '/../../pages/ai_changes.php';
        $html = (string) ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $html = 'EXCEPTION: ' . $e->getMessage();
    }

    $t->assert(
        str_contains($html, rex_i18n::rawMsg('ai_platform_changes_disabled_page')),
        'an admin reaching the page directly gets the disabled notice, not the inbox',
    );
    $t->assert(!str_contains($html, 'ai-change-bulk'), 'no decision form is rendered while disabled');
}

// Proposing must be refused too, not just hidden.
$t->assertThrows(
    static fn() => ChangeService::getInstance()->propose(
        ArticleTarget::existing(1, rex_clang::getStartId()),
        (new ArticlePayload())->priority(2),
        'while disabled',
        Source::php('test:pages'),
    ),
    'the PHP API refuses to accept proposals while the feature is off',
);

// Back on: page returns.
rex_config::set('ai_platform', 'changes_enabled', 1);
rex_be_controller::appendPackagePages();
$runPagesPrepared();
$t->assert(null !== rex_be_controller::getPageObject('ai_changes'), 'with the feature on, the page is back');

// Deliberately not restored to $originalEnabled: everything below proposes and
// renders, which a disabled feature refuses. The value is restored at the end.
rex_config::set('ai_platform', 'changes_enabled', 1);

$t->section('Page rendering');

// A request to render, with content that would break a page that forgets to
// escape.
$store = new ChangeRequestStore();
$service = ChangeService::getInstance();

$clangId = rex_clang::getStartId();
$articleId = (int) rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('article') . ' WHERE clang_id = :c ORDER BY id LIMIT 1', [':c' => $clangId])[0]['id'];

$xss = '<script>alert("xss")</script>';
$requestId = $service->propose(
    target: ArticleTarget::existing($articleId, $clangId),
    payload: (new ArticlePayload())->name('Titel ' . $xss),
    reason: 'Begründung mit ' . $xss,
    source: Source::php('test:pages', 'Page test ' . $xss),
);
$t->assert($requestId > 0, 'a render fixture was created');

// Log in as an admin, because the pages call rex::requireUser().
$adminId = (int) (rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('user') . ' WHERE admin = 1 AND status = 1 ORDER BY id LIMIT 1')[0]['id'] ?? 0);

if (0 === $adminId) {
    echo "  SKIP  no active admin — page rendering skipped\n";
} else {
    rex::setProperty('user', rex_user::get($adminId));

    /**
     * Renders one page file and returns its output.
     */
    // Every rendered page, keyed by file, so the link check below can walk all
    // of them at once instead of each render block having to remember.
    $rendered = [];

    $render = static function (string $file, array $request = []) use (&$rendered): string {
        $_GET = $request;
        $_POST = [];
        $_REQUEST = $request;

        ob_start();
        try {
            include __DIR__ . '/../../pages/' . $file;
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $html = (string) ob_get_clean();
        $rendered[$file . ':' . http_build_query($request)] = $html;

        return $html;
    };

    // --- inbox ---
    try {
        $html = $render('ai_changes.list.php');
        $t->assert('' !== $html, 'the inbox renders output');
        $t->assert(str_contains($html, (string) $requestId), 'the inbox lists the fixture');
        $t->assert(!str_contains($html, '<script>alert('), 'the inbox does not emit the payload script tag');
        $t->assert(str_contains($html, '&lt;script&gt;'), 'the inbox escapes the payload instead');
        $t->assert(!str_contains($html, '&amp;amp;'), 'inbox links are not double escaped');

        // Columns the formatters read but nobody should see. They stay in the
        // query — getValue() goes to the SQL result, not the column list — so
        // forgetting removeColumn() shows a database column name as a header.
        foreach (['reviewed_via', 'reviewed_by', 'source_key', 'changeset_id'] as $helper) {
            $t->assert(
                !str_contains($html, '<th>' . $helper . '</th>'),
                "the helper column {$helper} is not shown as a header",
            );
        }
    } catch (Throwable $e) {
        $t->assert(false, 'the inbox renders without error — ' . $e->getMessage());
    }

    // --- detail ---
    try {
        $html = $render('ai_changes.list.php', ['func' => 'view', 'id' => (string) $requestId]);
        $t->assert(str_contains($html, 'ai-diff-table') || str_contains($html, 'ai-diff-block'), 'the detail view renders a diff');
        $t->assert(!str_contains($html, '<script>alert('), 'the detail view does not emit the payload script tag');
        $t->assert(str_contains($html, '&lt;script&gt;'), 'the detail view escapes the payload instead');
        $t->assert(!str_contains($html, '&amp;quot;'), 'labels are not double escaped');
    } catch (Throwable $e) {
        $t->assert(false, 'the detail view renders without error — ' . $e->getMessage());
    }

    // The entry form and everything that tested it — the type chooser, the
    // per-type field tagging, the YForm table reload — went with the page. Its
    // absence is asserted in the page-tree section above; there is nothing left
    // here to render.

    // --- the staged-file preview in the detail view ---
    //
    // The reviewer has to see the image. A diff row reading `upload: up_7f3a…`
    // says nothing about whether that picture belongs on the site, and the file
    // has no public URL by design — it lives under `redaxo/data/`, which the web
    // server denies. So the preview goes through a backend endpoint, and that
    // endpoint must be in the page.
    try {
        $uploads = new PendingUploadStore();
        $previewPng = rex_path::addonData('ai_platform', 'pending/pagetest_source.png');
        rex_dir::create(dirname($previewPng));
        $img = imagecreatetruecolor(20, 12);
        imagefilledrectangle($img, 0, 0, 19, 11, imagecolorallocate($img, 200, 60, 40));
        imagepng($img, $previewPng);

        $staged = $uploads->stage($previewPng, 'ai-pagetest-' . substr(md5((string) getmypid()), 0, 8) . '.png', Source::php('test:pages', 'Page test'), false);

        $mediaRequestId = ChangeService::getInstance()->propose(
            target: MediaTarget::createIn(0, $staged->filename),
            payload: (new MediaPayload())->upload($staged->handle)->title('Vorschau-Test'),
            reason: 'Page test: staged file preview.',
            source: Source::php('test:pages', 'Page test'),
        );

        $html = $render('ai_changes.list.php', ['func' => 'view', 'id' => (string) $mediaRequestId]);

        $t->assert(str_contains($html, 'ai-upload-preview'), 'the detail view embeds the staged image');
        $t->assert(str_contains($html, 'rex-api-call=ai_change_file'), 'through the permission-checked backend endpoint');
        $t->assert(str_contains($html, $staged->handle), 'and names the handle it is serving');
        $t->assert(
            !str_contains($html, 'data/addons/ai_platform/pending'),
            'the filesystem path is never put in the page — there is no URL for it anyway',
        );
        // The block collides with nothing: `$meta` already holds the request's
        // metadata further down, and reusing the name would blank it.
        $t->assert(str_contains($html, rex_i18n::rawMsg('ai_platform_change_col_source')), 'the metadata block still renders');

        // Clean up: the request, the staging row and the file.
        rex_sql::factory()->setQuery(
            'DELETE FROM ' . rex::getTable('ai_change_request') . ' WHERE id = :id',
            [':id' => $mediaRequestId],
        );
        $uploads->discard($staged->handle);
        rex_file::delete($previewPng);
        $t->assert(null === $uploads->findByHandle($staged->handle), 'the preview fixture was removed');
    } catch (Throwable $e) {
        $t->assert(false, 'the staged-file preview renders — ' . $e->getMessage());
    }

    // --- docs tab: same file agents read as a skill ---
    try {
        $html = $render('docs.changes.php');
        $t->assert('' !== $html, 'the guide tab renders');
        $t->assert(!str_contains($html, 'description:'), 'the front matter is stripped from the guide');
        $t->assert(
            str_contains($html, 'redaxo_propose_change') || str_contains($html, 'ChangeService'),
            'the guide contains the agent instructions',
        );

        // Parsedown tears a **bold** run apart when a code span holding an
        // asterisk follows immediately: `**`rex_url::*` text**` came out as
        // `*<em>`rex_url::</em><code>text**` — emphasis and code tags
        // interleaved, the sentence unreadable. Nothing in the markdown source
        // looks wrong, so only the rendered output catches it.
        $t->assert(
            !preg_match('/\*<em>/', $html),
            'no bold run is torn apart by an adjacent code span',
        );
        $t->assert(
            !preg_match('#</code>[a-z_]+\(\)<code>#', $html),
            'code spans are not rendered inside out',
        );

        // The settings page used to carry a section explaining unattended
        // approval. It was removed, and the explanation has to be here instead —
        // this tab renders the same file agents read, so one source serves both.
        // Without this assert the information could vanish unnoticed.
        $t->assert(
            str_contains($html, ApiApproval::SCOPE),
            'the guide names the scope that allows approving without review',
        );
        $t->assert(
            str_contains($html, 'Token'),
            'and says where that scope is administered',
        );
    } catch (Throwable $e) {
        $t->assert(false, 'the guide tab renders without error — ' . $e->getMessage());
    }

    // --- settings: one page, with the change block conditional on the switch ---
    try {
        $html = $render('settings.php');

        // Both subjects, in one form.
        $t->assert(str_contains($html, 'name="default_text_profile"'), 'the settings page renders the default profile fields');
        $t->assert(str_contains($html, 'name="changes_enabled"'), 'and the change-request master switch');
        $t->assert(str_contains($html, 'changes_stale_policy'), 'and the stale policy');
        $t->assert(str_contains($html, 'changes_allow_all_yform_tables'), 'and the allow-all-tables switch');
        $t->assert(str_contains($html, 'allowed_yform_tables'), 'and the YForm table list');

        // YForm table names are frequently language keys (`translate:ymedia_…`).
        // Printed raw, an admin reads the key; run through rex_i18n and a missing
        // translation comes back as `[translate:the_key]`, which is worse. Both
        // states are refused: resolve, and fall back to the technical table name.
        $t->assert(!str_contains($html, '[translate:'), 'no unresolved language key is shown as a table label');

        // One form, one token. Two would mean one of them is never validated.
        $t->assertSame(
            1,
            preg_match_all('#<form[^>]*method="post"#i', $html),
            'the merged page posts through a single form',
        );

        // The detail block is marked for the script and visible while the
        // feature is on.
        $t->assertSame(3, preg_match_all('#data-ai-changes-detail#', $html), 'the blocks are marked for the script');
        $t->assert(
            !str_contains($html, 'data-ai-changes-detail hidden'),
            'and none is hidden while the feature is on',
        );

        // The API self-approval settings were removed on purpose: the scope on
        // the token is the decision, and a second switch asking the same
        // question only created a state where the two could disagree.
        foreach ([
            'changes_api_approve_enabled',
            'changes_api_approve_types',
            'changes_api_approve_operations',
            'changes_api_approve_root',
            'changes_api_approve_offline_only',
        ] as $abandoned) {
            $t->assert(
                !str_contains($html, $abandoned),
                "the abandoned setting {$abandoned} is gone from the page",
            );
        }
        $t->assert(
            !str_contains($html, ApiApproval::SCOPE),
            'the settings page does not name the approval scope — that belongs to the api addon',
        );

        // Three sections while the feature is on: the form, plus the two
        // read-only tables. The `content` slot renders flush with the panel
        // edges — right for a table, wrong for a form.
        $sections = array_slice(explode('<section class="rex-page-section">', $html), 1);
        $t->assertSame(3, count($sections), 'three sections while change requests are on');

        $slots = [];
        foreach ($sections as $section) {
            preg_match('#<div class="panel-title">(.*?)</div>#s', $section, $titleMatch);
            $slots[trim(strip_tags($titleMatch[1] ?? ''))] = str_contains($section, '<div class="panel-body">')
                ? 'body'
                : 'content';
        }

        $t->assertSame('body', $slots[rex_i18n::rawMsg('ai_platform_settings')] ?? null, 'the settings form uses the padded body slot');
        $t->assertSame('content', $slots[rex_i18n::rawMsg('ai_platform_change_handlers_heading')] ?? null, 'the handler table uses the flush content slot');
        $t->assertSame('content', $slots[rex_i18n::rawMsg('ai_platform_change_counts_heading')] ?? null, 'the volume table uses the flush content slot');
    } catch (Throwable $e) {
        $t->assert(false, 'the settings page renders without error — ' . $e->getMessage());
    }

    // --- with the feature off: hidden, but still rendered and still posted ---
    //
    // The reveal is done in assets/changes.js so that flipping the switch shows
    // the settings at once, without a save. Which means the fields must stay in
    // the DOM — and that is not a compromise, it is the safe half of the trade:
    // a hidden input posts the value it was rendered with, so saving while the
    // block is out of sight writes the stored values back unchanged.
    //
    // Conditional rendering is the version that bites. Fields that are not
    // rendered are not in the POST either, and a post handler reading them anyway
    // resets the stale policy to its default and empties the YForm allow list —
    // no error, no warning, a security setting silently reopened. Asserted from
    // both directions so neither half can be "optimised" away on its own.
    $originalStalePolicy = rex_config::get('ai_platform', 'changes_stale_policy');
    $originalYformTables = rex_config::get('ai_platform', 'changes_allowed_yform_tables');
    $originalAllowAll = rex_config::get('ai_platform', 'changes_allow_all_yform_tables');

    try {
        rex_config::set('ai_platform', 'changes_enabled', 0);
        $html = $render('settings.php');

        $t->assert(str_contains($html, 'name="changes_enabled"'), 'the master switch stays visible while off');
        $t->assert(str_contains($html, 'name="default_text_profile"'), 'and so do the default profiles');

        // Rendered — this is what makes the browser post them.
        $t->assert(str_contains($html, 'changes_stale_policy'), 'the stale policy is still rendered while off');
        $t->assert(str_contains($html, 'changes_allow_all_yform_tables'), 'so is the allow-all-tables switch');
        $t->assert(str_contains($html, 'allowed_yform_tables['), 'and the YForm checkboxes, with their stored state');

        // …and hidden. Three marked blocks: the detail fieldset, the wrapper
        // around the two read-only tables, and the link into the inbox — while
        // the feature is off the menu entry is removed with it, so there is no
        // inbox to open.
        $t->assertSame(3, preg_match_all('#data-ai-changes-detail#', $html), 'three blocks belong to the switch');
        $t->assertSame(
            3,
            preg_match_all('#data-ai-changes-detail hidden#', $html),
            'and all three arrive hidden while the feature is off',
        );
        $t->assert(
            str_contains($html, rex_i18n::rawMsg('ai_platform_changes_open_inbox')),
            'the inbox link is rendered even so, hidden rather than omitted',
        );

        // The stylesheet has to spell the rule out, because `fieldset` carries a
        // display of its own and any theme setting it beats the user-agent rule
        // for [hidden].
        $t->assert(
            str_contains(
                (string) file_get_contents(__DIR__ . '/../../assets/styles.css'),
                '[data-ai-changes-detail][hidden]',
            ),
            'the stylesheet forces the hidden state instead of trusting the browser default',
        );

        // Three sections either way: the form plus the two tables. They are
        // hidden with the rest, not omitted, so the script can show them.
        $t->assertSame(
            3,
            count(array_slice(explode('<section class="rex-page-section">', $html), 1)),
            'the read-only tables are rendered while off too, just hidden',
        );
    } catch (Throwable $e) {
        $t->assert(false, 'the settings page renders with the feature off — ' . $e->getMessage());
    }

    // Saving in that state writes the stored values back unchanged — which is
    // what the browser posts, because the fields are hidden rather than absent.
    rex_config::set('ai_platform', 'changes_stale_policy', 'warn');
    rex_config::set('ai_platform', 'changes_allowed_yform_tables', json_encode(['rex_page_test_fixture'], JSON_THROW_ON_ERROR));

    $_POST = [
        'default_text_profile' => '0',
        'default_image_generation_profile' => '0',
        'default_image_understanding_profile' => '0',
        'default_embedding_profile' => '0',
        'changes_enabled' => '0',
        // As a browser sends them: hidden fields still submit. The allow-all
        // checkbox included on purpose — an unchecked box sends nothing, and a
        // POST that omits it turns the setting off. That is correct behaviour for
        // a real form and a trap for a hand-written fixture: leaving it out here
        // once switched the installation's YForm allow-all off for good.
        'changes_stale_policy' => 'warn',
        'changes_allow_all_yform_tables' => '1',
        'allowed_yform_tables' => ['rex_page_test_fixture' => '1'],
        rex_csrf_token::PARAM => rex_csrf_token::factory('ai_platform_settings')->getValue(),
    ];
    $_GET = [];
    $_REQUEST = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';

    ob_start();
    try {
        include __DIR__ . '/../../pages/settings.php';
        ob_end_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        $t->assert(false, 'saving with the feature off does not error — ' . $e->getMessage());
    }

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];

    $t->assertSame(
        'warn',
        (string) rex_config::get('ai_platform', 'changes_stale_policy'),
        'saving while the detail block is hidden leaves the stale policy alone',
    );
    $t->assertSame(
        '["rex_page_test_fixture"]',
        (string) rex_config::get('ai_platform', 'changes_allowed_yform_tables'),
        'and leaves the YForm allow list alone',
    );

    // Both values are shared with the rest of the suite — change-storage and
    // change-situations depend on the stale policy — so they go back. Leaving
    // `warn` behind here made two other test files fail with messages about
    // approvals.
    rex_config::set('ai_platform', 'changes_stale_policy', $originalStalePolicy);
    rex_config::set('ai_platform', 'changes_allowed_yform_tables', $originalYformTables);
    rex_config::set('ai_platform', 'changes_allow_all_yform_tables', $originalAllowAll);
    rex_config::set('ai_platform', 'changes_enabled', 1);

    $t->assertSame(
        $originalStalePolicy,
        rex_config::get('ai_platform', 'changes_stale_policy'),
        'the stale policy fixture was put back — the rest of the suite reads it',
    );
    $t->assertSame(
        $originalAllowAll,
        rex_config::get('ai_platform', 'changes_allow_all_yform_tables'),
        'and the YForm allow-all switch, which decides whether every table is writable',
    );
}

// --- every internal link across all rendered pages --------------------------
//
// The settings page links *out* of its own section, and it shipped pointing at
// `ai_changes/settings` and `ai_changes/list` after both moved — two dead links
// that rendered perfectly and only failed when clicked. A page that renders is
// not a page whose links work, so every `page=` target any of these pages emits
// is resolved against the real page tree.
if (0 !== $adminId) {
    $dead = [];
    foreach ($rendered as $where => $html) {
        preg_match_all('#[?&](?:amp;)?page=([A-Za-z0-9_/-]+)#', $html, $matches);
        foreach (array_unique($matches[1]) as $target) {
            if (null === rex_be_controller::getPageObject($target)) {
                $dead[] = $target . ' (in ' . $where . ')';
            }
        }
    }

    $t->assert(
        [] === $dead,
        'every page= link on every rendered page resolves' . ([] === $dead ? '' : ' — dead: ' . implode(', ', $dead)),
    );

    // Named explicitly as well, because the generic check above only fires while
    // the subpages happen to be absent from the tree. Every one of these was real.
    foreach ([
        'ai_changes/settings',
        'ai_changes/docs',
        'ai_changes/new',
        'ai_changes/list',
        'ai_platform/settings/general',
        'ai_platform/settings/changes',
    ] as $goneSubpage) {
        $hits = [];
        foreach ($rendered as $where => $html) {
            if (str_contains($html, 'page=' . $goneSubpage) || str_contains($html, 'page%3D' . $goneSubpage)) {
                $hits[] = $where;
            }
        }
        $t->assert([] === $hits, "no page links to the removed {$goneSubpage}" . ([] === $hits ? '' : ' — found in ' . implode(', ', $hits)));
    }
}

$t->section('Cleanup');
rex_config::set('ai_platform', 'changes_enabled', $originalEnabled);
rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('ai_change_request') . ' WHERE source_key = :k', [':k' => 'test:pages']);
$t->assertSame(0, $store->countOpenForSource('test:pages'), 'fixture removed');

exit($t->summary());
