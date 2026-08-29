<?php

/**
 * The situations a change request has to survive between submission and review.
 *
 * A proposal sits in the inbox for hours or days, and the world moves in the
 * meantime. Each section here breaks one thing deliberately and checks that the
 * right finding comes back — and, where the finding must block, that approval
 * actually refuses.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\ChangeStatus;
use FriendsOfRedaxo\AiPlatform\Change\Payload\ArticlePayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\MediaPayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\SlicePayload;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\StaleTargetException;
use FriendsOfRedaxo\AiPlatform\Change\TargetGoneException;
use FriendsOfRedaxo\AiPlatform\Change\Target\ArticleTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\MediaTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\SliceTarget;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;

$t = new AiTestRunner('Change request situations');
$service = ChangeService::getInstance();
$store = new ChangeRequestStore();
$source = Source::php('test:situations', 'Situation test');
$clangId = rex_clang::getStartId();

$adminId = (int) (rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('user') . ' WHERE admin = 1 AND status = 1 ORDER BY id LIMIT 1')[0]['id'] ?? 0);
if (0 === $adminId) {
    echo "No active admin user. Aborting.\n";
    exit(1);
}
$admin = rex_user::get($adminId);
rex::setProperty('user', $admin);

$cleanup = [];

// ===========================================================================
$t->section('A media file referenced by the proposal is deleted');

// Build a fixture article and slice we own, so nothing real gets broken.
$categoryId = (int) (rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('article') . ' WHERE startarticle = 1 AND clang_id = :c LIMIT 1', [':c' => $clangId])[0]['id'] ?? 0);

$moduleRow = rex_sql::factory()->getArray('SELECT id FROM ' . rex::getTable('module') . ' ORDER BY id LIMIT 1')[0] ?? null;

if (null === $moduleRow) {
    echo "  SKIP  no module in this installation\n";
} else {
    $moduleId = (int) $moduleRow['id'];

    // A media record whose file we can remove without touching a real asset.
    $fixtureFile = 'ai-change-test-fixture.txt';
    $fixturePath = rex_path::media($fixtureFile);
    rex_file::put($fixturePath, 'fixture');

    $mediaSql = rex_sql::factory();
    $mediaSql->setTable(rex::getTable('media'));
    // A run that aborts halfway leaves this row behind, and the next run then
    // fails on a duplicate-key error that has nothing to do with what is being
    // tested — it hides the original fault instead of reporting it. Clearing
    // first makes the fixture idempotent.
    rex_sql::factory()->setQuery(
        'DELETE FROM ' . rex::getTable('media') . ' WHERE filename = :f',
        [':f' => $fixtureFile],
    );

    $mediaSql->setValue('filename', $fixtureFile);
    $mediaSql->setValue('originalname', $fixtureFile);
    $mediaSql->setValue('filetype', 'text/plain');
    $mediaSql->setValue('filesize', 7);
    $mediaSql->setValue('category_id', 0);
    $mediaSql->setValue('title', 'Fixture');
    $mediaSql->addGlobalCreateFields();
    $mediaSql->insert();
    $mediaId = (int) $mediaSql->getLastId();
    rex_media_cache::delete($fixtureFile);

    $cleanup[] = static function () use ($mediaId, $fixtureFile, $fixturePath): void {
        rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('media') . ' WHERE id = :id', [':id' => $mediaId]);
        rex_media_cache::delete($fixtureFile);
        rex_file::delete($fixturePath);
    };

    $t->assert(null !== rex_media::get($fixtureFile), 'fixture media record created');

    // A slice we can point at.
    $sliceArticleId = (int) (rex_sql::factory()
        ->getArray('SELECT id FROM ' . rex::getTable('article') . ' WHERE clang_id = :c LIMIT 1', [':c' => $clangId])[0]['id']);
    $sliceMessage = rex_content_service::addSlice($sliceArticleId, $clangId, 1, $moduleId, ['value1' => 'Fixture-Slice']);
    $sliceId = (int) (rex_sql::factory()->getArray(
        'SELECT id FROM ' . rex::getTable('article_slice') . ' WHERE article_id = :a AND clang_id = :c ORDER BY id DESC LIMIT 1',
        [':a' => $sliceArticleId, ':c' => $clangId],
    )[0]['id'] ?? 0);
    $t->assert($sliceId > 0, "fixture slice created (#{$sliceId})");

    $cleanup[] = static function () use ($sliceId, $sliceArticleId, $clangId): void {
        rex_content_service::deleteSlice($sliceId);
        rex_content_service::generateArticleContent($sliceArticleId, $clangId);
    };

    // Propose a change that references the fixture media file.
    $requestId = $service->propose(
        target: SliceTarget::existing($sliceId, $sliceArticleId),
        payload: (new SlicePayload())->value(1, 'Mit Bild')->media(1, $fixtureFile),
        reason: 'Situation test: media reference.',
        source: $source,
    );
    $t->assert($service->inspectTarget($store->find($requestId))->isClean(), 'while the file exists, the proposal inspects clean');

    // Now delete the media record, as a colleague would in the media pool.
    rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('media') . ' WHERE id = :id', [':id' => $mediaId]);
    rex_media_cache::delete($fixtureFile);
    rex_media::clearInstance($fixtureFile);

    $inspection = $service->inspectTarget($store->find($requestId));
    $t->assert($inspection->hasBrokenReferences(), 'deleting the referenced file is reported as a broken reference');
    $t->assert(
        str_contains(implode(' ', $inspection->brokenReferences), $fixtureFile),
        'the finding names the missing file',
    );
    $t->assert(!$inspection->isOverridable(), 'a broken reference is not overridable');
    $t->assert($inspection->blocksApproval(false), 'a broken reference blocks even with the warn-only policy');

    // And approval must refuse — with force, too.
    $t->assertThrows(
        static fn() => $service->approve($requestId, $admin),
        'approving a proposal with a dangling media reference is refused',
    );
    $t->assertThrows(
        static fn() => $service->approve($requestId, $admin, true),
        'even an explicit override cannot apply a dangling reference',
    );
    $t->assertSame(ChangeStatus::Pending, $store->find($requestId)?->status, 'the refused request stays pending');
}

// ===========================================================================
$t->section('A linked article is deleted');

if (isset($sliceId) && $sliceId > 0) {
    // Submitting an already-dangling link is refused right away — the caller
    // gets the error while it can still do something about it.
    $t->assertThrows(
        static fn() => $service->propose(
            SliceTarget::existing($sliceId, $sliceArticleId),
            (new SlicePayload())->link(1, 999999),
            'link to a non-existent article',
            $source,
        ),
        'proposing a link to a non-existent article is refused at submission',
        '999999',
    );

    // The interesting case is a link that breaks *after* submission.
    rex_article_service::addArticle([
        'category_id' => $categoryId,
        'priority' => 1,
        'name' => 'AI-Change-Test-Linkziel',
    ]);
    $linkTargetId = (int) (rex_sql::factory()->getArray(
        'SELECT id FROM ' . rex::getTable('article') . ' WHERE name = :n AND clang_id = :c ORDER BY id DESC LIMIT 1',
        [':n' => 'AI-Change-Test-Linkziel', ':c' => $clangId],
    )[0]['id'] ?? 0);

    if ($linkTargetId < 1) {
        echo "  SKIP  could not create a link target article\n";
    } else {
        $linkRequestId = $service->propose(
            target: SliceTarget::existing($sliceId, $sliceArticleId),
            payload: (new SlicePayload())->linkList(1, [$linkTargetId]),
            reason: 'Situation test: link target deleted later.',
            source: $source,
        );
        $t->assert($service->inspectTarget($store->find($linkRequestId))->isClean(), 'the proposal inspects clean while the link target exists');

        rex_article_service::deleteArticle($linkTargetId);
        rex_article::clearInstancePool();

        $inspection = $service->inspectTarget($store->find($linkRequestId));
        $t->assert($inspection->hasBrokenReferences(), 'deleting the link target is reported as a broken reference');
        $t->assert(
            str_contains(implode(' ', $inspection->brokenReferences), (string) $linkTargetId),
            'the finding names the missing article',
        );
        $t->assertThrows(
            static fn() => $service->approve($linkRequestId, $admin, true),
            'a proposal with a dangling link cannot be approved even with override',
        );
    }
} else {
    echo "  SKIP  no fixture slice\n";
}

// ===========================================================================
$t->section('The target itself is deleted');

$throwawayId = null;
$message = rex_article_service::addArticle([
    'category_id' => $categoryId,
    'priority' => 1,
    'name' => 'AI-Change-Test-Wegwerfartikel',
]);
$throwawayId = (int) (rex_sql::factory()->getArray(
    'SELECT id FROM ' . rex::getTable('article') . ' WHERE name = :n AND clang_id = :c ORDER BY id DESC LIMIT 1',
    [':n' => 'AI-Change-Test-Wegwerfartikel', ':c' => $clangId],
)[0]['id'] ?? 0);

if ($throwawayId < 1) {
    echo "  SKIP  could not create a throwaway article\n";
} else {
    $goneRequestId = $service->propose(
        target: ArticleTarget::existing($throwawayId, $clangId),
        payload: (new ArticlePayload())->name('Neuer Name'),
        reason: 'Situation test: target deleted.',
        source: $source,
    );
    $t->assert($service->inspectTarget($store->find($goneRequestId))->isClean(), 'the proposal inspects clean while the article exists');

    rex_article_service::deleteArticle($throwawayId);
    rex_article::clearInstancePool();

    $inspection = $service->inspectTarget($store->find($goneRequestId));
    $t->assert($inspection->gone, 'a deleted target is reported as gone');
    $t->assert(!$inspection->isOverridable(), 'a gone target is not overridable');

    $threw = false;
    try {
        $service->approve($goneRequestId, $admin, true);
    } catch (TargetGoneException) {
        $threw = true;
    }
    $t->assert($threw, 'approving a proposal whose target is gone throws');
    $t->assertSame(ChangeStatus::Expired, $store->find($goneRequestId)?->status, 'the request is marked expired');
}

// ===========================================================================
$t->section('The text has already been changed by hand');

if (isset($sliceId) && $sliceId > 0) {
    $textRequestId = $service->propose(
        target: SliceTarget::existing($sliceId, $sliceArticleId),
        payload: (new SlicePayload())->value(1, 'Vorschlag des Agenten'),
        reason: 'Situation test: concurrent manual edit.',
        source: $source,
    );

    // A human edits the same slice afterwards.
    rex_sql::factory()->setQuery(
        'UPDATE ' . rex::getTable('article_slice') . ' SET value1 = :v WHERE id = :id',
        [':v' => 'Von Hand geändert', ':id' => $sliceId],
    );

    $inspection = $service->inspectTarget($store->find($textRequestId));
    $t->assert($inspection->changed, 'the manual edit is detected');
    $t->assert(in_array('value1', $inspection->changedFields, true), 'the finding names value1');
    $t->assert($inspection->isOverridable(), 'a changed target can be overridden deliberately');
    $t->assertSame('Von Hand geändert', (string) ($inspection->currentValues['value1'] ?? ''), 'the inspection carries the current value for the three-column diff');

    $blocked = false;
    try {
        $service->approve($textRequestId, $admin);
    } catch (StaleTargetException) {
        $blocked = true;
    }
    $t->assert($blocked, 'approval is blocked without an override');

    $result = $service->approve($textRequestId, $admin, true);
    $service->flushCacheRebuilds();
    $t->assert($result->success, 'approval succeeds with an explicit override');
}

// ===========================================================================
$t->section('The article has gained slices in the meantime');

if (isset($moduleId) && isset($sliceArticleId)) {
    // A create proposal has no target of its own — the context snapshot is the
    // only thing that can notice a changed environment.
    $createRequestId = $service->propose(
        target: SliceTarget::createIn($sliceArticleId, $clangId, $moduleId, 1, 1),
        payload: (new SlicePayload())->value(1, 'Neuer Slice an Position 1'),
        reason: 'Situation test: context change.',
        source: $source,
    );

    $created = $store->find($createRequestId);
    $t->assert(null !== $created?->contextHash, 'a create proposal records a context fingerprint');
    $t->assert($service->inspectTarget($created)->isClean(), 'the create proposal inspects clean at first');

    // Someone adds content to the same ctype.
    rex_content_service::addSlice($sliceArticleId, $clangId, 1, $moduleId, ['value1' => 'Inzwischen dazugekommen']);
    $extraSliceId = (int) (rex_sql::factory()->getArray(
        'SELECT id FROM ' . rex::getTable('article_slice') . ' WHERE article_id = :a AND clang_id = :c ORDER BY id DESC LIMIT 1',
        [':a' => $sliceArticleId, ':c' => $clangId],
    )[0]['id'] ?? 0);
    $cleanup[] = static function () use ($extraSliceId, $sliceArticleId, $clangId): void {
        if ($extraSliceId > 0) {
            rex_content_service::deleteSlice($extraSliceId);
            rex_content_service::generateArticleContent($sliceArticleId, $clangId);
        }
    };

    $inspection = $service->inspectTarget($store->find($createRequestId));
    $t->assert($inspection->contextChanged, 'the new sibling slice is detected as a context change');
    $t->assert([] !== $inspection->contextChanges, 'the finding describes what changed');
    $t->assert(
        str_contains(implode(' ', $inspection->contextChanges), 'slice_count'),
        'the description names the slice count',
    );
    $t->assert(!$inspection->blocksApproval(true), 'a context change warns but does not block');

    $result = $service->approve($createRequestId, $admin);
    $service->flushCacheRebuilds();
    $t->assert($result->success, 'the create can still be approved despite the context change');

    $newSliceId = (int) ($result->createdIds['slice_id'] ?? 0);
    if ($newSliceId > 0) {
        $cleanup[] = static function () use ($newSliceId, $sliceArticleId, $clangId): void {
            rex_content_service::deleteSlice($newSliceId);
            rex_content_service::generateArticleContent($sliceArticleId, $clangId);
        };
    }
}

// ===========================================================================
$t->section('A YForm dataset changed in the meantime');

if (!FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry::has('yform')) {
    echo "  SKIP  YForm not installed\n";
} else {
    $candidate = null;
    foreach (rex_yform_manager_table::getAll() as $table) {
        foreach (FriendsOfRedaxo\AiPlatform\Change\Support\YformFieldRegistry::forTable($table->getTableName()) as $name => $field) {
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
        echo "  SKIP  no YForm table with a text field and data\n";
    } else {
        $originalAllowList = rex_config::get('ai_platform', 'changes_allowed_yform_tables', '');
        rex_config::set('ai_platform', 'changes_allowed_yform_tables', json_encode([$candidate['table']], JSON_THROW_ON_ERROR));

        $dataset = rex_yform_manager_dataset::get($candidate['id'], $candidate['table']);
        $originalValue = (string) $dataset?->getValue($candidate['field']);

        $yformRequestId = $service->propose(
            target: FriendsOfRedaxo\AiPlatform\Change\Target\YformTarget::existing($candidate['table'], $candidate['id']),
            payload: FriendsOfRedaxo\AiPlatform\Change\Payload\YformPayload::forTable($candidate['table'])
                ->set($candidate['field'], 'Vorschlag des Agenten'),
            reason: 'Situation test: dataset edited meanwhile.',
            source: $source,
        );
        $t->assert($service->inspectTarget($store->find($yformRequestId))->isClean(), 'the yform proposal inspects clean at first');

        // Somebody edits the dataset.
        rex_sql::factory()->setQuery(
            'UPDATE `' . $candidate['table'] . '` SET `' . $candidate['field'] . '` = :v WHERE id = :id',
            [':v' => 'Von Hand geändert', ':id' => $candidate['id']],
        );

        $inspection = $service->inspectTarget($store->find($yformRequestId));
        $t->assert($inspection->changed, 'the concurrent dataset edit is detected');
        $t->assert(in_array($candidate['field'], $inspection->changedFields, true), 'the finding names the edited field');

        $blocked = false;
        try {
            $service->approve($yformRequestId, $admin);
        } catch (StaleTargetException) {
            $blocked = true;
        }
        $t->assert($blocked, 'approval of the changed dataset is blocked');

        // Restore.
        rex_sql::factory()->setQuery(
            'UPDATE `' . $candidate['table'] . '` SET `' . $candidate['field'] . '` = :v WHERE id = :id',
            [':v' => $originalValue, ':id' => $candidate['id']],
        );
        rex_config::set('ai_platform', 'changes_allowed_yform_tables', $originalAllowList);
    }
}

// ===========================================================================
$t->section('A YForm dataset is read past its instance cache');

// rex_yform_manager_dataset pools instances per (table, id), and save() writes
// back the whole loaded row. A warm cache would therefore make a snapshot report
// "unchanged" for an edited row, and make a write reset fields nobody proposed
// changing. Both paths have to bypass the pool.
if (FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry::has('yform')) {
    $cacheCandidate = null;
    foreach (rex_yform_manager_table::getAll() as $table) {
        foreach (FriendsOfRedaxo\AiPlatform\Change\Support\YformFieldRegistry::forTable($table->getTableName()) as $name => $field) {
            if (!in_array((string) $field->getTypeName(), ['text', 'textarea'], true)) {
                continue;
            }
            $row = rex_sql::factory()->getArray('SELECT id FROM `' . $table->getTableName() . '` ORDER BY id LIMIT 1');
            if ([] !== $row) {
                $cacheCandidate = ['table' => $table->getTableName(), 'field' => $name, 'id' => (int) $row[0]['id']];
                break 2;
            }
        }
    }

    if (null === $cacheCandidate) {
        echo "  SKIP  no YForm table with a text field and data\n";
    } else {
        $originalCacheValue = (string) rex_sql::factory()->getArray(
            'SELECT `' . $cacheCandidate['field'] . '` FROM `' . $cacheCandidate['table'] . '` WHERE id = :id',
            [':id' => $cacheCandidate['id']],
        )[0][$cacheCandidate['field']];

        // Warm the pool, the way any earlier access in the same request would.
        rex_yform_manager_dataset::get($cacheCandidate['id'], $cacheCandidate['table'])?->getValue($cacheCandidate['field']);

        rex_sql::factory()->setQuery(
            'UPDATE `' . $cacheCandidate['table'] . '` SET `' . $cacheCandidate['field'] . '` = :v WHERE id = :id',
            [':v' => 'EXTERN-GEAENDERT', ':id' => $cacheCandidate['id']],
        );

        $handler = FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry::require('yform');
        $values = $handler->readCurrent(
            FriendsOfRedaxo\AiPlatform\Change\Target\YformTarget::existing($cacheCandidate['table'], $cacheCandidate['id']),
        );

        $t->assertSame(
            'EXTERN-GEAENDERT',
            (string) ($values[$cacheCandidate['field']] ?? ''),
            'readCurrent() sees an external edit despite a warm instance pool',
        );

        // The pooled instance still holds the old value — proof the assertion
        // above is actually testing something.
        $t->assert(
            'EXTERN-GEAENDERT' !== (string) rex_yform_manager_dataset::get($cacheCandidate['id'], $cacheCandidate['table'])?->getValue($cacheCandidate['field']),
            'the pooled instance is indeed stale, so the check is meaningful',
        );

        rex_sql::factory()->setQuery(
            'UPDATE `' . $cacheCandidate['table'] . '` SET `' . $cacheCandidate['field'] . '` = :v WHERE id = :id',
            [':v' => $originalCacheValue, ':id' => $cacheCandidate['id']],
        );
        rex_yform_manager_dataset::clearInstance([$cacheCandidate['table'], $cacheCandidate['id']]);
    }
}

// ===========================================================================
$t->section('The module of a slice is removed');

if (isset($sliceId) && $sliceId > 0) {
    $moduleRequestId = $service->propose(
        target: SliceTarget::existing($sliceId, $sliceArticleId),
        payload: (new SlicePayload())->value(1, 'Egal'),
        reason: 'Situation test: module removed.',
        source: $source,
    );

    // Point the slice at a module id that does not exist, which is what a
    // deleted module leaves behind.
    $realModuleId = (int) (rex_sql::factory()->getArray(
        'SELECT module_id FROM ' . rex::getTable('article_slice') . ' WHERE id = :id',
        [':id' => $sliceId],
    )[0]['module_id']);
    rex_sql::factory()->setQuery(
        'UPDATE ' . rex::getTable('article_slice') . ' SET module_id = 999999 WHERE id = :id',
        [':id' => $sliceId],
    );

    $inspection = $service->inspectTarget($store->find($moduleRequestId));
    $t->assert($inspection->hasBrokenReferences(), 'a missing module is reported as a broken reference');

    $t->assertThrows(
        static fn() => $service->approve($moduleRequestId, $admin, true),
        'a slice whose module is gone cannot be approved',
    );

    rex_sql::factory()->setQuery(
        'UPDATE ' . rex::getTable('article_slice') . ' SET module_id = :m WHERE id = :id',
        [':m' => $realModuleId, ':id' => $sliceId],
    );
}

// ===========================================================================
$t->section('Cleanup');

foreach (array_reverse($cleanup) as $undo) {
    try {
        $undo();
    } catch (Throwable $e) {
        echo '  NOTE  cleanup step failed: ' . $e->getMessage() . "\n";
    }
}

rex_sql::factory()->setQuery('DELETE FROM ' . rex::getTable('ai_change_request') . ' WHERE source_key = :k', [':k' => 'test:situations']);
$t->assertSame(0, $store->countOpenForSource('test:situations'), 'test rows removed');

exit($t->summary());
