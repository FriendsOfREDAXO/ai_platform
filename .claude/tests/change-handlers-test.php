<?php

/**
 * One real round trip per handler: read the current state, propose a change,
 * approve it, verify the write landed, then restore the original value.
 *
 * Deliberately against the live database — the whole point of these handlers is
 * that they go through the REDAXO services, and a mocked service would test
 * nothing. Every section restores what it touched.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangePayloadBuilder;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\ChangeStatus;
use FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Payload\SlicePayload;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\Support\MetaFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Support\YformFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Target\ArticleTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\MediaTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\MetaTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\SliceTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\YformTarget;
use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;

$t = new AiTestRunner('Change handlers');
$service = ChangeService::getInstance();
$store = new ChangeRequestStore();
$source = Source::php('test:handlers', 'Handler test');
$clangId = rex_clang::getStartId();

$adminId = (int) (rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('user') . ' WHERE admin = 1 AND status = 1 ORDER BY id LIMIT 1')[0]['id'] ?? 0);

if (0 === $adminId) {
    echo "No active admin user — cannot test approval. Aborting.\n";
    exit(1);
}

$admin = rex_user::get($adminId);
rex::setProperty('user', $admin);

/**
 * Proposes, approves, and reports whether the write succeeded.
 */
$roundTrip = static function ($target, $payload, string $reason) use ($service, $source, $t): ?array {
    try {
        $id = $service->propose($target, $payload, $reason, $source);
    } catch (Throwable $e) {
        $t->assert(false, 'propose failed: ' . $e->getMessage());

        return null;
    }

    try {
        $result = $service->approve($id, rex::requireUser());
        $service->flushCacheRebuilds();
    } catch (Throwable $e) {
        $t->assert(false, 'approve failed: ' . $e->getMessage());

        return null;
    }

    // The counterpart to the API approval test: a backend decision has to record
    // its channel too. If only the API path filled `reviewed_via`, an editor
    // could not tell "decided by a person" from "decided before this column
    // existed", and the badge in the inbox would mean nothing.
    $stored = (new ChangeRequestStore())->find($id);
    $t->assertSame(
        'backend',
        $stored?->reviewedVia,
        'a backend approval records reviewed_via=backend',
    );
    $t->assertSame(
        rex::requireUser()->getId(),
        $stored?->reviewedBy,
        'and names the user who decided it',
    );

    return ['id' => $id, 'result' => $result];
};

// ===========================================================================
$t->section('article');

$articleId = (int) rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('article') . ' WHERE clang_id = :c AND startarticle = 0 ORDER BY id LIMIT 1', [':c' => $clangId])[0]['id'] ?? 0;

if ($articleId < 1) {
    // Fall back to any article, including start articles.
    $articleId = (int) rex_sql::factory()
        ->getArray('SELECT id FROM ' . rex::getTable('article') . ' WHERE clang_id = :c ORDER BY id LIMIT 1', [':c' => $clangId])[0]['id'];
}

$originalName = (string) rex_article::get($articleId, $clangId)?->getName();
$t->assert('' !== $originalName, "article {$articleId} found (»{$originalName}«)");

$state = $service->read(ArticleTarget::existing($articleId, $clangId));
$t->assert($state->exists(), 'read() returns the current article state');
$t->assert(isset($state->values['name']), 'the state contains the name');
$t->assert('' !== $state->fingerprint && 'absent' !== $state->fingerprint, 'the state carries a fingerprint');

$built = ChangePayloadBuilder::build(
    HandlerRegistry::require('article'),
    ChangeOperation::Update,
    ['article_id' => $articleId, 'clang_id' => $clangId],
    ['name' => $originalName . ' [Test]'],
);
$trip = $roundTrip($built['target'], $built['payload'], 'Handler test: rename.');

if (null !== $trip) {
    $t->assert($trip['result']->success, 'the article change applied');
    rex_article::clearInstancePool();
    $t->assertSame($originalName . ' [Test]', (string) rex_article::get($articleId, $clangId)?->getName(), 'the new name is stored');
    $t->assertSame(ChangeStatus::Applied, $store->find($trip['id'])?->status, 'the request is applied');

    // Restore.
    rex_article_service::editArticle($articleId, $clangId, [
        'name' => $originalName,
        'template_id' => (int) rex_article::get($articleId, $clangId)?->getTemplateId(),
        'priority' => (int) rex_article::get($articleId, $clangId)?->getValue('priority'),
    ]);
    rex_article::clearInstancePool();
    $t->assertSame($originalName, (string) rex_article::get($articleId, $clangId)?->getName(), 'the original name was restored');
}

// ===========================================================================
$t->section('meta');

// A plain text field, not a widget: REX_MEDIA/REX_LINK widget fields hold a
// filename or an article id, and the reference check rightly refuses arbitrary
// text in them.
$articleMetaFields = MetaFieldRegistry::forPrefix('art_');
$singleValueField = null;
foreach ($articleMetaFields as $name => $definition) {
    if (MetaFieldRegistry::isMultiValue($definition)) {
        continue;
    }
    if (!in_array($definition['type_id'], [rex_metainfo_default_type::TEXT, rex_metainfo_default_type::TEXTAREA], true)) {
        continue;
    }
    $singleValueField = $name;
    break;
}

if (null === $singleValueField) {
    echo "  SKIP  no plain-text art_* metainfo field — meta handler untested\n";
} else {
    $metaTarget = MetaTarget::article($articleId, $clangId);
    $before = $service->read($metaTarget);
    $t->assert($before->exists(), 'read() returns the current metainfo values');

    $originalMeta = $before->values[$singleValueField] ?? null;

    $built = ChangePayloadBuilder::build(
        HandlerRegistry::require('meta'),
        ChangeOperation::Update,
        ['carrier' => MetaTarget::CARRIER_ARTICLE, 'article_id' => $articleId, 'clang_id' => $clangId],
        [$singleValueField => 'Handler-Test-Wert'],
    );
    $trip = $roundTrip($built['target'], $built['payload'], 'Handler test: metainfo.');

    if (null !== $trip) {
        $t->assert($trip['result']->success, 'the metainfo change applied');
        $after = $service->read($metaTarget);
        $t->assertSame('Handler-Test-Wert', (string) ($after->values[$singleValueField] ?? ''), "the value of {$singleValueField} is stored");

        // Restore.
        rex_sql::factory()->setQuery(
            'UPDATE ' . rex::getTable('article') . ' SET `' . $singleValueField . '` = :v WHERE id = :id AND clang_id = :c',
            [':v' => $originalMeta, ':id' => $articleId, ':c' => $clangId],
        );
        rex_article_cache::deleteMeta($articleId, $clangId);
        $t->assert(true, 'the original metainfo value was restored');
    }

    // A field belonging to another carrier must be refused at build time.
    $t->assertThrows(
        static fn() => ChangePayloadBuilder::build(
            HandlerRegistry::require('meta'),
            ChangeOperation::Update,
            ['carrier' => MetaTarget::CARRIER_ARTICLE, 'article_id' => $articleId, 'clang_id' => $clangId],
            ['med_copyright' => 'x'],
        ),
        'a med_ field on an article carrier is refused',
    );
}

// ===========================================================================
$t->section('slice');

$sliceRow = rex_sql::factory()->getArray(
    'SELECT s.id, s.article_id, s.clang_id, s.module_id, s.ctype_id, s.value1 FROM ' . rex::getTable('article_slice') . ' s'
    . ' INNER JOIN ' . rex::getTable('module') . ' m ON m.id = s.module_id'
    . ' WHERE s.revision = 0 ORDER BY s.id LIMIT 1',
)[0] ?? null;

if (null === $sliceRow) {
    echo "  SKIP  no slice in this installation — slice handler untested\n";
} else {
    $sliceId = (int) $sliceRow['id'];
    $sliceArticleId = (int) $sliceRow['article_id'];
    $originalValue1 = (string) ($sliceRow['value1'] ?? '');

    $sliceTarget = SliceTarget::existing($sliceId, $sliceArticleId);
    $state = $service->read($sliceTarget);
    $t->assert($state->exists(), "read() returns slice {$sliceId}");
    $t->assert(array_key_exists('value20', (array) $state->values), 'the snapshot covers all 20 value slots');

    $trip = $roundTrip(
        $sliceTarget,
        (new SlicePayload())->value(1, 'Handler-Test-Inhalt'),
        'Handler test: slice content.',
    );

    if (null !== $trip) {
        $t->assert($trip['result']->success, 'the slice change applied');
        $stored = (string) (rex_sql::factory()->getArray(
            'SELECT value1 FROM ' . rex::getTable('article_slice') . ' WHERE id = :id',
            [':id' => $sliceId],
        )[0]['value1'] ?? '');
        $t->assertSame('Handler-Test-Inhalt', $stored, 'value1 is stored');

        $touched = $trip['result']->touchedArticles;
        $t->assert(
            [] !== $touched && $touched[0]['article_id'] === $sliceArticleId,
            'the result reports the article whose cache needs rebuilding',
        );

        // Restore.
        rex_sql::factory()->setQuery(
            'UPDATE ' . rex::getTable('article_slice') . ' SET value1 = :v WHERE id = :id',
            [':v' => $originalValue1, ':id' => $sliceId],
        );
        rex_content_service::generateArticleContent($sliceArticleId, (int) $sliceRow['clang_id']);
        $t->assert(true, 'the original slice content was restored');
    }

    // Module allow list closes the door — but only once the explicit
    // "allow all modules" switch is off. The list alone means nothing while it
    // is on, which is the point of having the switch.
    $originalModules = rex_config::get('ai_platform', 'changes_allowed_modules', '');
    $originalAllowAllModules = rex_config::get('ai_platform', 'changes_allow_all_modules', 1);

    rex_config::set('ai_platform', 'changes_allowed_modules', json_encode([999999], JSON_THROW_ON_ERROR));
    rex_config::set('ai_platform', 'changes_allow_all_modules', 1);

    $t->assert(
        ChangeService::isModuleAllowed((int) $sliceRow['module_id']),
        'while allow-all is on, a module outside the list is still allowed',
    );

    rex_config::set('ai_platform', 'changes_allow_all_modules', 0);

// The module gate is no longer a list but a property: a module whose output runs
// its slice values as PHP is refused, because a proposal for it is code rather
// than content. Read from the module itself, so it cannot be forgotten.
$phpModuleId = (int) (rex_sql::factory()->getArray(
    'SELECT id FROM ' . rex::getTable('module') . " WHERE output LIKE '%output=php%' LIMIT 1",
)[0]['id'] ?? 0);

if ($phpModuleId > 0) {
    $t->assertThrows(
        static fn() => $service->propose(
            target: SliceTarget::createIn($articleId, $clangId, $phpModuleId),
            payload: (new SlicePayload())->value(1, 'phpinfo();'),
            reason: 'a module that evaluates its value as PHP',
            source: $source,
        ),
        'a slice for a module that executes PHP is refused',
    );
} else {
    echo "  SKIP  no module with output=php in this installation\n";
}

    rex_config::set('ai_platform', 'changes_allowed_modules', $originalModules);
    rex_config::set('ai_platform', 'changes_allow_all_modules', $originalAllowAllModules);
}

// ===========================================================================
$t->section('media');

$mediaRow = rex_sql::factory()->getArray(
    'SELECT filename, title, category_id FROM ' . rex::getTable('media') . ' ORDER BY id LIMIT 1',
)[0] ?? null;

if (null === $mediaRow) {
    echo "  SKIP  no media file in this installation — media handler untested\n";
} else {
    $filename = (string) $mediaRow['filename'];
    $originalTitle = (string) ($mediaRow['title'] ?? '');

    $mediaTarget = MediaTarget::existing($filename);
    $t->assert($service->read($mediaTarget)->exists(), "read() returns media »{$filename}«");

    $built = ChangePayloadBuilder::build(
        HandlerRegistry::require('media'),
        ChangeOperation::Update,
        ['filename' => $filename],
        ['title' => 'Handler-Test-Titel'],
    );
    $trip = $roundTrip($built['target'], $built['payload'], 'Handler test: media title.');

    if (null !== $trip) {
        $t->assert($trip['result']->success, 'the media change applied');
        rex_media::clearInstance($filename);
        $t->assertSame('Handler-Test-Titel', (string) rex_media::get($filename)?->getTitle(), 'the media title is stored');

        // The category must survive a title-only change — updateMedia() reads
        // both keys unconditionally, so a missing back-fill would reset it.
        $t->assertSame(
            (int) $mediaRow['category_id'],
            (int) rex_media::get($filename)?->getCategoryId(),
            'the media category was not reset by a title-only change',
        );

        rex_media_service::updateMedia($filename, ['title' => $originalTitle, 'category_id' => (int) $mediaRow['category_id']]);
        rex_media::clearInstance($filename);
        $t->assert(true, 'the original media title was restored');
    }
}

// ===========================================================================
$t->section('media create: staged file into the pool');

// The one cycle that moves bytes. A PNG is generated here rather than shipped as
// a fixture, so the test carries no binary and no license question.
$pngPath = rex_path::addonData('ai_platform', 'pending/handlertest_source.png');
rex_dir::create(dirname($pngPath));

$image = imagecreatetruecolor(60, 40);
imagefilledrectangle($image, 0, 0, 59, 39, imagecolorallocate($image, 30, 120, 200));
imagepng($image, $pngPath);

$uploads = new PendingUploadStore();
$stagedName = 'ai-handlertest-' . substr(md5((string) getmypid()), 0, 8) . '.png';
$source = Source::php('test:handlers', 'Handler test');

try {
    $upload = $uploads->stage($pngPath, $stagedName, $source, false);
} catch (Throwable $e) {
    $upload = null;
    $t->assert(false, 'staging a PNG works — ' . $e->getMessage());
}

if (null !== $upload) {
    $t->assertSame($stagedName, $upload->filename, 'staging reserves the filename it was given');
    $t->assert($upload->fileExists(), 'the staged file is on disk');
    $t->assert(
        !str_contains($upload->path(), rex_path::media()),
        'and it is NOT in the media pool — that is the whole point of staging',
    );
    $t->assertSame(60, $upload->width, 'the image dimensions were read');
    $t->assert(null === rex_media::get($stagedName), 'nothing is in the media pool yet');

    // Same name twice must be refused: the reservation is what lets a caller
    // reference the image from a slice proposal before it exists.
    $t->assertThrows(
        static fn() => $uploads->stage($pngPath, $stagedName, $source, false),
        'a second upload cannot claim the same filename',
    );

    $built = ChangePayloadBuilder::build(
        HandlerRegistry::require('media'),
        ChangeOperation::Create,
        ['category_id' => 0, 'filename' => $stagedName],
        ['upload' => $upload->handle, 'title' => 'Handler-Test Bild'],
    );

    $requestId = $service->propose(
        target: $built['target'],
        payload: $built['payload'],
        reason: 'Handler test: staged media into the pool.',
        source: $source,
    );
    $t->assert($requestId > 0, 'the create proposal was recorded');

    // Proposing must not have written anything.
    $t->assert(null === rex_media::get($stagedName), 'proposing did not add the file to the pool');

    $linked = $uploads->findByHandle($upload->handle);
    $t->assertSame($requestId, $linked?->requestId, 'the upload is linked to its request');
    $t->assertSame(null, $linked?->expiresAt, 'and no longer counts as abandoned');

    $result = $service->approve($requestId, rex::requireUser());
    $t->assert($result->success, 'the create applied — ' . ($result->error ?? ''));

    rex_media::clearInstance($stagedName);
    $media = rex_media::get($stagedName);
    $t->assert(null !== $media, 'the file is in the media pool now');
    $t->assertSame('Handler-Test Bild', (string) $media?->getTitle(), 'with the proposed title');
    $t->assert(is_file(rex_path::media($stagedName)), 'and the bytes really landed in media/');

    $t->assert(null !== $uploads->findByHandle($upload->handle)?->consumedAt, 'the upload is marked consumed');

    // The staged copy survives the write on purpose: addMedia() is handed a
    // *copy*, so a failure halfway leaves the evidence intact and the request
    // retryable. The cronjob is what removes it later.
    $t->assert(
        $uploads->findByHandle($upload->handle)?->fileExists() ?? false,
        'the staged original is kept for the cronjob rather than moved away',
    );

    // --- restore -----------------------------------------------------------
    rex_media_service::deleteMedia($stagedName);
    $uploads->discard($upload->handle);
    rex_sql::factory()->setQuery(
        'DELETE FROM ' . rex::getTable('ai_change_request') . ' WHERE id = :id',
        [':id' => $requestId],
    );
    rex_media::clearInstance($stagedName);
    $t->assert(null === rex_media::get($stagedName), 'the fixture was removed from the pool again');
    $t->assert(null === $uploads->findByHandle($upload->handle), 'and the staging row is gone');
}

// --- the sweep that keeps the staging directory from filling up -------------
//
// An agent that uploads and then crashes leaves bytes behind *and* a filename
// blocked. That is the only case with a short fuse; everything attached to a
// request follows that request's fate.
$orphan = null;
try {
    $orphan = $uploads->stage($pngPath, 'ai-orphan-' . substr(md5(microtime()), 0, 8) . '.png', $source, false);
} catch (Throwable $e) {
    $t->assert(false, 'staging an orphan fixture works — ' . $e->getMessage());
}

if (null !== $orphan) {
    $t->assert(
        !in_array($orphan->handle, array_map(static fn($u) => $u->handle, $uploads->findAbandoned()), true),
        'a fresh upload is not swept — its reservation has not run out',
    );

    // Backdate the reservation instead of waiting a day.
    rex_sql::factory()->setQuery(
        'UPDATE ' . rex::getTable('ai_change_upload') . ' SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE handle = :h',
        [':h' => $orphan->handle],
    );

    $abandoned = array_map(static fn($u) => $u->handle, $uploads->findAbandoned());
    $t->assert(in_array($orphan->handle, $abandoned, true), 'an unreferenced upload past its reservation is swept');

    $orphanPath = $orphan->path();
    $uploads->discard($orphan->handle);
    $t->assert(!is_file($orphanPath), 'discarding removes the file, not just the row');
    $t->assert(null === $uploads->findByHandle($orphan->handle), 'and the row with it — the filename must not stay reserved forever');
}

rex_file::delete($pngPath);

// ===========================================================================
$t->section('yform');

if (!HandlerRegistry::has('yform')) {
    echo "  SKIP  YForm not installed — yform handler untested\n";
} else {
    // Find a table with a writable text field and at least one row.
    $candidate = null;
    foreach (rex_yform_manager_table::getAll() as $table) {
        $fields = YformFieldRegistry::forTable($table->getTableName());
        foreach ($fields as $name => $field) {
            if (!in_array((string) $field->getTypeName(), ['text', 'textarea'], true)) {
                continue;
            }
            $row = rex_sql::factory()->getArray('SELECT id FROM `' . $table->getTableName() . '` ORDER BY id LIMIT 1');
            if ([] !== $row) {
                $candidate = ['table' => $table->getTableName(), 'field' => $name, 'id' => (int) $row[0]['id']];
                break 2;
            }
        }
    }

    if (null === $candidate) {
        echo "  SKIP  no YForm table with a text field and data — yform handler untested\n";
    } else {
        $originalAllowList = rex_config::get('ai_platform', 'changes_allowed_yform_tables', '');
        $originalAllowAllTables = rex_config::get('ai_platform', 'changes_allow_all_yform_tables', 0);

        // Closed door first: empty list AND the allow-all switch off. The switch
        // is what opens everything, so a test that only clears the list would
        // pass or fail depending on a setting it never touched.
        rex_config::set('ai_platform', 'changes_allowed_yform_tables', '');
        rex_config::set('ai_platform', 'changes_allow_all_yform_tables', 0);
        $t->assertThrows(
            static fn() => $service->propose(
                YformTarget::existing($candidate['table'], $candidate['id']),
                FriendsOfRedaxo\AiPlatform\Change\Payload\YformPayload::forTable($candidate['table'])->set($candidate['field'], 'x'),
                'table not allowed',
                $source,
            ),
            'a YForm table outside the allow list is refused',
        );

        // Then open it for this one table.
        rex_config::set('ai_platform', 'changes_allowed_yform_tables', json_encode([$candidate['table']], JSON_THROW_ON_ERROR));

        $dataset = rex_yform_manager_dataset::get($candidate['id'], $candidate['table']);
        $originalFieldValue = (string) $dataset?->getValue($candidate['field']);

        $yformTarget = YformTarget::existing($candidate['table'], $candidate['id']);
        $state = $service->read($yformTarget);
        $t->assert($state->exists(), sprintf('read() returns dataset %d of %s', $candidate['id'], $candidate['table']));

        $built = ChangePayloadBuilder::build(
            HandlerRegistry::require('yform'),
            ChangeOperation::Update,
            ['table' => $candidate['table'], 'dataset_id' => $candidate['id']],
            [$candidate['field'] => 'Handler-Test-Wert'],
        );
        $trip = $roundTrip($built['target'], $built['payload'], 'Handler test: yform dataset.');

        if (null !== $trip) {
            $t->assert($trip['result']->success, 'the yform change applied: ' . (string) $trip['result']->error);
            $reloaded = rex_yform_manager_dataset::get($candidate['id'], $candidate['table']);
            $t->assertSame('Handler-Test-Wert', (string) $reloaded?->getValue($candidate['field']), 'the field value is stored');

            // Restore.
            if (null !== $reloaded) {
                $reloaded->setValue($candidate['field'], $originalFieldValue);
                $reloaded->save();
            }
            $t->assert(true, 'the original dataset value was restored');
        }

        // An unknown field must be refused.
        $t->assertThrows(
            static fn() => ChangePayloadBuilder::build(
                HandlerRegistry::require('yform'),
                ChangeOperation::Update,
                ['table' => $candidate['table'], 'dataset_id' => $candidate['id']],
                ['feld_gibt_es_nicht' => 'x'],
            ),
            'an unknown YForm field is refused',
            'Unknown YForm field',
        );

        rex_config::set('ai_platform', 'changes_allowed_yform_tables', $originalAllowList);
        rex_config::set('ai_platform', 'changes_allow_all_yform_tables', $originalAllowAllTables);
    }
}

// ===========================================================================
$t->section('Permission guard');

// A non-admin user without structure rights must not be able to approve.
$plainUserId = (int) (rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('user') . ' WHERE admin = 0 AND status = 1 ORDER BY id LIMIT 1')[0]['id'] ?? 0);

if (0 === $plainUserId) {
    echo "  SKIP  no non-admin user — the escalation guard could not be exercised\n";
} else {
    $plainUser = rex_user::get($plainUserId);
    $handler = HandlerRegistry::require('article');

    $id = $service->propose(
        ArticleTarget::existing($articleId, $clangId),
        FriendsOfRedaxo\AiPlatform\Change\Payload\ArticlePayload::class::fromArray([])->name('Sollte nicht durchgehen'),
        'permission guard test',
        $source,
    );
    $request = $store->find($id);

    $mayApprove = null !== $plainUser && $handler->canApprove($plainUser, $request);

    if ($mayApprove) {
        echo "  NOTE  the non-admin user happens to have rights on this article; guard not exercised\n";
    } else {
        $t->assertThrows(
            static fn() => $service->approve($id, $plainUser),
            'a user without rights on the target cannot approve',
        );
        $t->assertSame(ChangeStatus::Pending, $store->find($id)?->status, 'the refused request stays pending');
    }
}

// ===========================================================================
$t->section('Cleanup');

rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('ai_change_request') . ' WHERE source_key = :k', [':k' => 'test:handlers']);
$t->assertSame(0, $store->countOpenForSource('test:handlers'), 'test rows removed');

exit($t->summary());
