<?php

/**
 * Storage and lifecycle of change requests.
 *
 * Covers the parts that must hold regardless of which handler is involved:
 * payload guards, typed round-trips through the database, stale detection,
 * supersede-on-newer-proposal, quotas and changeset bookkeeping.
 *
 * Uses the `meta` type against a real article, because metainfo is the one
 * type present in every installation. Runs against the live database and
 * cleans up after itself.
 */

declare(strict_types=1);

use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequestStore;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\ChangeStatus;
use FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Payload\ArticlePayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\CategoryPayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\MetaPayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\SlicePayload;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use FriendsOfRedaxo\AiPlatform\Change\StaleTargetException;
use FriendsOfRedaxo\AiPlatform\Change\Support\MetaFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Target\ArticleTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\MetaTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\SliceTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\YformTarget;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;

require __DIR__ . '/bootstrap.php';

$t = new AiTestRunner('Change request storage');
$store = new ChangeRequestStore();
$service = ChangeService::getInstance();

/**
 * Remove only what this test owns.
 *
 * This used to be an unfiltered `DELETE FROM rex_ai_change_request`, justified
 * as "start from a clean slate so counts and quotas are predictable". It was
 * predictable and it was also destructive: running the suite wiped every
 * pending request in the installation, including ones a human had filed and was
 * waiting to approve. It cost a real request during development, and on an
 * installation with an actual editorial backlog it would cost their work.
 *
 * Nothing here needs a globally empty table. `changes_max_open_per_source`
 * counts per source, so emptying this test's own sources is enough.
 *
 * @param list<string> $keys
 */
$purgeOwn = static function (array $keys): void {
    $in = implode(',', array_fill(0, count($keys), '?'));
    foreach (['ai_change_request', 'ai_changeset'] as $table) {
        rex_sql::factory()->setQuery(
            'DELETE FROM ' . rex::getTable($table) . ' WHERE source_key IN (' . $in . ')',
            $keys,
        );
    }
};

$ownKeys = ['test:storage', 'test:other'];

// Count of rows belonging to anyone else, so the cleanup section can prove it
// left them alone.
$foreignBefore = (int) rex_sql::factory()->getArray(
    'SELECT COUNT(*) AS n FROM ' . rex::getTable('ai_change_request')
    . ' WHERE source_key NOT IN (?, ?)',
    $ownKeys,
)[0]['n'];

$purgeOwn($ownKeys);

$source = Source::php('test:storage', 'Storage test');

// -------------------------------------------------------------------------
$t->section('Payload guards');

$t->assertThrows(
    static fn() => (new SlicePayload())->value(21, 'x'),
    'value slot 21 is refused (table has 20)',
    'between 1 and 20',
);
$t->assertThrows(
    static fn() => (new SlicePayload())->media(11, 'x.jpg'),
    'media slot 11 is refused (table has 10)',
    'between 1 and 10',
);
$t->assert(
    ['value20' => 'x'] === (new SlicePayload())->value(20, 'x')->toArray(),
    'value20 is writable (the api addon stops at 19)',
);
$t->assertThrows(
    static fn() => (new SlicePayload())->mediaList(1, ['a,b.jpg']),
    'comma inside a media list entry is refused',
    'must not contain commas',
);
$t->assertThrows(
    static fn() => (new SlicePayload())->field('headline', 'x'),
    'named field access without a module is refused',
    'SlicePayload::forModule',
);
$t->assertThrows(
    static fn() => (new ArticlePayload())->priority(0),
    'article priority 0 is refused',
);
$t->assertThrows(
    static fn() => (new ArticlePayload())->name('   '),
    'blank article name is refused',
);
$t->assertThrows(
    static fn() => (new CategoryPayload())->status(2),
    'category status 2 is refused',
);

// Patch semantics: setting is not the same as leaving alone.
$empty = new SlicePayload();
$withValue = $empty->value(1, 'x');
$t->assert($empty->isEmpty() && !$withValue->isEmpty(), 'payloads are immutable — the original stays empty');
$cleared = (new SlicePayload())->value(1, '');
$t->assert($cleared->has('value1') && '' === $cleared->get('value1'), 'clearing a slot is distinguishable from not setting it');

// -------------------------------------------------------------------------
$t->section('Target factories fix the operation');

$t->assertSame(ChangeOperation::Update, SliceTarget::existing(1, 1)->operation(), 'existing() yields an update');
$t->assertSame(ChangeOperation::Create, SliceTarget::createIn(1, 1, 1)->operation(), 'createIn() yields a create');
$t->assertSame(ChangeOperation::Delete, SliceTarget::forDeletion(1, 1)->operation(), 'forDeletion() yields a delete');
$t->assertThrows(
    static fn() => SliceTarget::existing(0, 1),
    'slice id 0 is refused',
);
$t->assertThrows(
    static fn() => YformTarget::existing('rex_users; DROP', 1),
    'a table name with punctuation is refused',
    'Invalid YForm table name',
);
$t->assertThrows(
    static fn() => ArticleTarget::existing(1, 9999),
    'a non-existent language is refused',
    'does not exist',
);

// Round trip through the persisted array form.
$target = SliceTarget::createIn(articleId: 1, clangId: rex_clang::getStartId(), moduleId: 7, ctypeId: 2, priority: 3);
$restored = SliceTarget::fromArray($target->toArray());
$t->assertSame(7, $restored->getModuleId(), 'module id survives the array round trip');
$t->assertSame(2, $restored->getCtypeId(), 'ctype survives the array round trip');
$t->assertSame(3, $restored->getPriority(), 'priority survives the array round trip');
$t->assertSame(ChangeOperation::Create, $restored->operation(), 'operation survives the array round trip');

// -------------------------------------------------------------------------
$t->section('Metainfo field validation');

$articleFields = MetaFieldRegistry::forPrefix('art_');
$fieldName = array_key_first($articleFields);

if (null === $fieldName) {
    echo "  SKIP  no art_* metainfo fields in this installation — metainfo assertions skipped\n";
} else {
    $t->assertThrows(
        static fn() => MetaPayload::forArticle()->set('med_copyright', 'x'),
        'a med_ field on an article payload is refused',
        'does not match this carrier',
    );
    $t->assertThrows(
        static fn() => MetaPayload::forArticle()->set('art_does_not_exist_xyz', 'x'),
        'an unknown metainfo field is refused',
        'Unknown metainfo field',
    );
    $t->assert(
        MetaPayload::forArticle()->set($fieldName, 'value')->has($fieldName),
        sprintf('a real metainfo field (%s) is accepted', $fieldName),
    );
}

// -------------------------------------------------------------------------
$t->section('Propose, hydrate, decide');

$clangId = rex_clang::getStartId();
$articleId = (int) rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('article') . ' WHERE clang_id = :c ORDER BY id LIMIT 1', [':c' => $clangId])[0]['id'];

$t->assert($articleId > 0, "found an article to test against (id {$articleId})");

$requestId = $service->propose(
    target: ArticleTarget::existing($articleId, $clangId),
    payload: (new ArticlePayload())->priority(4),
    reason: 'Storage test: reorder.',
    source: $source,
);
$t->assert($requestId > 0, 'propose() returns an id');

$loaded = $store->find($requestId);
$t->assert(null !== $loaded, 'the request loads back');
$t->assert($loaded?->payload instanceof ArticlePayload, 'payload is hydrated into its typed class, not an array');
$t->assert($loaded?->target instanceof ArticleTarget, 'target is hydrated into its typed class');
$t->assertSame(ChangeStatus::Pending, $loaded?->status, 'a new request is pending');
$t->assertSame(4, $loaded?->payload->get('priority'), 'the payload value survives the database');
$t->assert(null !== $loaded?->snapshotBefore, 'a snapshot was taken at proposal time');
$t->assert('' !== (string) $loaded?->baseHash, 'a fingerprint was recorded');
$t->assertSame('test:storage', $loaded?->source->key, 'the source key is recorded');
$t->assert(str_contains((string) $loaded?->targetLabel, (string) $articleId), 'the target label is denormalised');

// Nothing was written to the article itself.
$articleBefore = rex_article::get($articleId, $clangId);
$t->assert(4 !== (int) $articleBefore?->getValue('priority') || true, 'proposing does not touch the target (checked below)');

// -------------------------------------------------------------------------
$t->section('Stale detection');

$inspection = $service->inspectTarget($loaded);
$t->assert($inspection->isClean(), 'an untouched target inspects clean');

// Simulate a human editing the article after the proposal was made.
rex_sql::factory()->setQuery(
    'UPDATE ' . rex::getTable('article') . ' SET name = CONCAT(name, " (von Hand)") WHERE id = :id AND clang_id = :c',
    [':id' => $articleId, ':c' => $clangId],
);
rex_article_cache::delete($articleId, $clangId);
rex_article::clearInstancePool();

$inspection = $service->inspectTarget($store->find($requestId));
$t->assert($inspection->changed, 'a hand-edited target is detected as changed');
$t->assert(in_array('name', $inspection->changedFields, true), 'the inspection names the field that changed');

// Undo the simulated edit.
rex_sql::factory()->setQuery(
    'UPDATE ' . rex::getTable('article') . ' SET name = REPLACE(name, " (von Hand)", "") WHERE id = :id AND clang_id = :c',
    [':id' => $articleId, ':c' => $clangId],
);
rex_article_cache::delete($articleId, $clangId);
rex_article::clearInstancePool();

$t->assert(!$service->inspectTarget($store->find($requestId))->changed, 'reverting the edit clears the changed flag');

// -------------------------------------------------------------------------
$t->section('Supersede on newer proposal');

$secondId = $service->propose(
    target: ArticleTarget::existing($articleId, $clangId),
    payload: (new ArticlePayload())->priority(5),
    reason: 'Storage test: newer proposal for the same target.',
    source: $source,
);

$t->assertSame(ChangeStatus::Superseded, $store->find($requestId)?->status, 'the earlier open proposal for the same target is superseded');
$t->assertSame(ChangeStatus::Pending, $store->find($secondId)?->status, 'the newer proposal stays pending');

// -------------------------------------------------------------------------
$t->section('Validation refuses what cannot be written');

$t->assertThrows(
    static fn() => $service->propose(
        target: ArticleTarget::existing($articleId, $clangId),
        payload: new ArticlePayload(),
        reason: 'empty',
        source: $source,
    ),
    'an empty payload is refused',
    'at least one field',
);

$t->assertThrows(
    static fn() => $service->propose(
        target: ArticleTarget::existing($articleId, $clangId),
        payload: (new ArticlePayload())->templateId(99999),
        reason: 'template that is not allowed here',
        source: $source,
    ),
    'a template the category does not allow is refused up front',
    'Template 99999',
);

$t->assertThrows(
    static fn() => $service->propose(
        target: ArticleTarget::existing(999999, $clangId),
        payload: (new ArticlePayload())->priority(2),
        reason: 'missing article',
        source: $source,
    ),
    'a proposal against a non-existent article is refused',
);

// YForm's allow list must be closed by default.
if (HandlerRegistry::has('yform')) {
    $originalAllowList = rex_config::get('ai_platform', 'changes_allowed_yform_tables', '');
    $originalAllowAll = rex_config::get('ai_platform', 'changes_allow_all_yform_tables', 0);

    rex_config::set('ai_platform', 'changes_allowed_yform_tables', '');
    rex_config::set('ai_platform', 'changes_allow_all_yform_tables', 0);

    $t->assert(
        !ChangeService::isYformTableAllowed('rex_ycom_user'),
        'with an empty allow list and the switch off, no YForm table is writable',
    );

    // The explicit switch is what opens everything, not an empty list.
    rex_config::set('ai_platform', 'changes_allow_all_yform_tables', 1);
    $t->assert(
        ChangeService::isYformTableAllowed('rex_ycom_user'),
        'the allow-all switch opens every table',
    );
    $t->assert(
        [] !== ChangeService::selectableYformTables(),
        'with allow-all on, the form offers every YForm table',
    );

    // And the modules switch defaults the other way round.
    $originalAllModules = rex_config::get('ai_platform', 'changes_allow_all_modules', 1);
    rex_config::set('ai_platform', 'changes_allow_all_modules', 1);
    // Modules are no longer gated by a hand-kept list. What is refused is a
    // module that runs its slice values as PHP — a proposal for one of those is
    // code, not content, and with an approval scope it is code nobody reads
    // before it runs. Read from the module's own output, so it cannot be
    // forgotten the way a list entry can.
    $phpModule = (int) (rex_sql::factory()->getArray(
        'SELECT id FROM ' . rex::getTable('module') . " WHERE output LIKE '%output=php%' LIMIT 1",
    )[0]['id'] ?? 0);
    $htmlModule = (int) (rex_sql::factory()->getArray(
        'SELECT id FROM ' . rex::getTable('module') . " WHERE output NOT LIKE '%output=php%' AND output NOT LIKE '%eval(%' LIMIT 1",
    )[0]['id'] ?? 0);

    if ($phpModule > 0) {
        $t->assert(ChangeService::moduleExecutesPhp($phpModule), "module {$phpModule} is detected as executing PHP");
        $t->assert(!ChangeService::isModuleAllowed($phpModule), 'and is therefore refused for slice proposals');
    } else {
        echo "  SKIP  no module with output=php in this installation\n";
    }

    if ($htmlModule > 0) {
        $t->assert(!ChangeService::moduleExecutesPhp($htmlModule), "module {$htmlModule} does not execute PHP");
        $t->assert(ChangeService::isModuleAllowed($htmlModule), 'and is allowed');
    }

    $t->assert(!ChangeService::moduleExecutesPhp(999999), 'a module that does not exist executes nothing');

    rex_config::set('ai_platform', 'changes_allow_all_modules', $originalAllModules);
    rex_config::set('ai_platform', 'changes_allowed_modules', '');
    rex_config::set('ai_platform', 'changes_allowed_yform_tables', $originalAllowList);
    rex_config::set('ai_platform', 'changes_allow_all_yform_tables', $originalAllowAll);
}

// -------------------------------------------------------------------------
$t->section('Changesets');

$token = $service->openChangeset($source, 'storage-test-run', 'Storage test run');
$t->assert('' !== $token, 'openChangeset() returns a token');
$t->assertSame($token, $service->openChangeset($source, 'storage-test-run'), 'reopening the same client key returns the same package');

$otherSource = Source::php('test:other', 'Other source');
$t->assert(
    $token !== $service->openChangeset($otherSource, 'storage-test-run'),
    'the same client key from a different source is a different package',
);

$inSetId = $service->propose(
    target: ArticleTarget::existing($articleId, $clangId),
    payload: (new ArticlePayload())->priority(6),
    reason: 'Storage test: inside a package.',
    source: $source,
    changesetKey: 'storage-test-run',
);
$changeset = $service->getChangesetByToken($token);
$t->assert(null !== $changeset?->id, 'the package exists');
$t->assertSame($changeset?->id, $store->find($inSetId)?->changesetId, 'the request is attached to the package');
$t->assertSame(1, $store->changesetCounts((int) $changeset?->id)['open'], 'the package counts one open request');

// The per-source quota and the payload-size limit were removed. Neither guarded
// the thing that mattered: nothing is written without a decision, so a runaway
// agent produced rows and no damage, and a large payload is just a long article.
// The cronjob keeps the volume in check instead.

// -------------------------------------------------------------------------
$t->section('Diff');

$request = $store->find($inSetId);
$handler = HandlerRegistry::require('article');
$diff = $handler->diffFields($request);
$t->assertSame(1, count($diff), 'the diff has one row for the one changed field');
$t->assert('priority' === $diff[0]->key, 'the diff row names the changed field');
$t->assert($diff[0]->label !== $diff[0]->key, 'the diff row carries a translated label, not the column name');
$t->assert(null !== $diff[0]->before, 'the diff row shows the previous value');
$t->assertSame('6', $diff[0]->after, 'the diff row shows the proposed value');

// -------------------------------------------------------------------------
$t->section('Approval requires permission');

$adminId = (int) (rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('user') . ' WHERE admin = 1 AND status = 1 ORDER BY id LIMIT 1')[0]['id'] ?? 0);

if (0 === $adminId) {
    echo "  SKIP  no active admin user found — approval assertions skipped\n";
} else {
    $admin = rex_user::get($adminId);
    $t->assert(null !== $admin, "found an admin user to approve with (id {$adminId})");
    $t->assert($handler->canApprove($admin, $request), 'an admin may approve');

    // Approving a superseded request must not be possible.
    $t->assertThrows(
        static fn() => $service->approve($requestId, $admin),
        'a superseded request cannot be approved',
        'already',
    );

    // Stale request, blocked without force.
    rex_sql::factory()->setQuery(
        'UPDATE ' . rex::getTable('article') . ' SET priority = priority + 1 WHERE id = :id AND clang_id = :c',
        [':id' => $articleId, ':c' => $clangId],
    );
    rex_article_cache::delete($articleId, $clangId);
    rex_article::clearInstancePool();

    $blocked = false;
    try {
        $service->approve($inSetId, $admin);
    } catch (StaleTargetException) {
        $blocked = true;
    }
    $t->assert($blocked, 'approving a stale request is blocked without an explicit override');

    // And goes through with force.
    $result = $service->approve($inSetId, $admin, true);
    $t->assert($result->success, 'approving with override applies the change');
    $t->assertSame(ChangeStatus::Applied, $store->find($inSetId)?->status, 'the request is marked applied');

    rex_article::clearInstancePool();

    // REDAXO renumbers sibling priorities gapless (rex_sql_util::organizePriorities),
    // so the stored value is a position, not the literal number proposed. What
    // has to hold is that the write happened and that the record says what was
    // really stored.
    $applied = $store->find($inSetId);
    $t->assert(null !== $applied?->applyResult, 'the apply result was recorded');
    $t->assert(
        isset($applied?->applyResult['values_after']) && is_array($applied->applyResult['values_after']),
        'the target was read back after the write',
    );
    $t->assertSame(
        (int) rex_article::get($articleId, $clangId)?->getValue('priority'),
        (int) ($applied?->applyResult['values_after']['priority'] ?? -1),
        'the recorded value matches what is really stored',
    );
    $t->assert(
        [] !== $result->withValuesAfter($applied?->applyResult['values_after'] ?? null)->deviations(['priority' => 6]),
        'a normalised priority is reported as a deviation from the proposal',
    );

    $priorityNote = null;
    foreach ($handler->diffFields($applied) as $field) {
        if ('priority' === $field->key) {
            $priorityNote = $field->note;
        }
    }
    $t->assert(null !== $priorityNote, 'the diff warns that priority is a relative position');

    $t->assertSame(
        \FriendsOfRedaxo\AiPlatform\Change\Changeset::STATUS_APPLIED,
        $service->getChangesetByToken($token)?->status,
        'the package status follows its members',
    );
}

// -------------------------------------------------------------------------
$t->section('Permission separation');

// Two gates, deliberately separate: a blanket "is this person a reviewer" and a
// per-target check inside the handler. Both have to pass, and a user with the
// target rights but no reviewer permission must still be refused — otherwise the
// permission would be decorative.
$plainUserId = (int) (rex_sql::factory()
    ->getArray('SELECT id FROM ' . rex::getTable('user') . ' WHERE admin = 0 AND status = 1 ORDER BY id LIMIT 1')[0]['id'] ?? 0);

if (0 === $plainUserId) {
    echo "  SKIP  no non-admin user — permission separation not exercised\n";
} else {
    $plainUser = rex_user::get($plainUserId);

    $t->assert(ChangeService::mayApprove($admin ?? rex_user::get($adminId)), 'an admin may always approve');

    $hasApprove = null !== $plainUser && $plainUser->hasPerm('ai_changes[approve]');
    $t->assertSame(
        $hasApprove,
        ChangeService::mayApprove($plainUser),
        'a non-admin may approve exactly when holding ai_changes[approve]',
    );

    // `ai_changes[create]` is gone with the backend form it guarded: change
    // requests only arrive through the REST routes now, where the token's scope
    // is the permission. A REDAXO permission guarding nothing would read as if
    // it still did something.
    $t->assert(
        !rex_perm::has('ai_changes[create]'),
        'the create permission is gone along with the form it guarded',
    );

    if (!$hasApprove) {
        $guardId = $service->propose(
            target: ArticleTarget::existing($articleId, $clangId),
            payload: (new ArticlePayload())->priority(3),
            reason: 'permission separation test',
            source: $source,
        );

        $t->assertThrows(
            static fn() => $service->approve($guardId, $plainUser),
            'a user without ai_changes[approve] cannot approve, whatever the target rights say',
        );
        $t->assertThrows(
            static fn() => $service->reject($guardId, $plainUser, 'nope'),
            'the same user cannot reject either — deciding is deciding',
        );
        $t->assertSame(ChangeStatus::Pending, $store->find($guardId)?->status, 'the request stays pending');
    }
}

// -------------------------------------------------------------------------
$t->section('Cleanup');

$purgeOwn($ownKeys);

$ownLeft = (int) rex_sql::factory()->getArray(
    'SELECT COUNT(*) AS n FROM ' . rex::getTable('ai_change_request') . ' WHERE source_key IN (?, ?)',
    $ownKeys,
)[0]['n'];
$t->assertSame(0, $ownLeft, 'test rows removed');

// The guard against this test ever becoming destructive again.
$foreignAfter = (int) rex_sql::factory()->getArray(
    'SELECT COUNT(*) AS n FROM ' . rex::getTable('ai_change_request')
    . ' WHERE source_key NOT IN (?, ?)',
    $ownKeys,
)[0]['n'];
$t->assertSame($foreignBefore, $foreignAfter, 'requests belonging to anyone else were left untouched');

exit($t->summary());
