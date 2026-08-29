<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Handler;

use FriendsOfRedaxo\AiPlatform\Change\AbstractHandler;
use FriendsOfRedaxo\AiPlatform\Change\ApplyResult;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\Diff\DiffField;
use FriendsOfRedaxo\AiPlatform\Change\Payload\SlicePayload;
use FriendsOfRedaxo\AiPlatform\Change\Support\ModuleFieldMap;
use FriendsOfRedaxo\AiPlatform\Change\Target\SliceTarget;
use FriendsOfRedaxo\AiPlatform\Change\TargetInterface;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;
use rex;
use rex_article;
use rex_article_cache;
use rex_clang;
use rex_content_service;
use rex_extension;
use rex_extension_point;
use rex_extension_point_art_content_updated;
use rex_i18n;
use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;
use rex_media;
use rex_sql;
use rex_sql_util;
use rex_user;

use function array_key_exists;
use function count;

/**
 * Content slices.
 *
 * Write paths differ per operation because the core does: rex_content_service
 * offers addSlice(), deleteSlice() and moveSlice(), but no editSlice(). An
 * update is therefore an UPDATE on rex_article_slice plus the same extension
 * point sequence the backend content page fires, plus a content regeneration.
 *
 * Unlike the api addon, this handler *does* fire the PRE extension points. The
 * api addon deliberately skips them because their main consumer, the
 * structure/history plugin, calls rex::requireUser() — which fails under token
 * auth where no backend user exists. Here the write happens inside the
 * reviewing editor's backend request, so a rex_user is present, history
 * snapshots are created, and they carry the right user. Going through approval
 * buys that for free.
 */
final class SliceHandler extends AbstractHandler
{
    public function getType(): string
    {
        return 'slice';
    }

    public function targetClass(): string
    {
        return SliceTarget::class;
    }

    public function payloadClass(): string
    {
        return SlicePayload::class;
    }

    public function readCurrent(TargetInterface $target): ?array
    {
        $row = $this->requireTarget($target)->loadRow();
        if (null === $row) {
            return null;
        }

        // Only content slots go into the snapshot. Structural columns are not
        // writable through a payload, and including createdate/updatedate would
        // make the fingerprint change for reasons nobody proposed.
        $values = [];
        foreach (SlicePayload::allSlots() as $slot) {
            $values[$slot] = array_key_exists($slot, $row) ? self::stringify($row[$slot]) : null;
        }

        return $values;
    }

    /**
     * The slices already sitting in the target ctype.
     *
     * For a create this is the whole basis for later checking: a proposal from
     * last week that inserts at position 1 has a very different effect once
     * somebody else has added content in front. For an update it catches the
     * case the user asked about — an article that had no slices now has some,
     * or the slice's neighbours were reordered around it.
     */
    public function readContext(TargetInterface $target): ?array
    {
        $target = $this->requireTarget($target);
        $clangId = $target->getClangId();
        if (null === $clangId) {
            return null;
        }

        // ctype is known upfront for a create; for an existing slice it comes
        // from the row.
        $ctypeId = $target->getCtypeId();
        if (null === $ctypeId) {
            $row = $target->loadRow();
            $ctypeId = null === $row ? 1 : (int) $row['ctype_id'];
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT id, module_id FROM ' . rex::getTable('article_slice')
            . ' WHERE article_id = :article AND clang_id = :clang AND ctype_id = :ctype AND revision = 0'
            . ' ORDER BY priority, id',
            [':article' => $target->getArticleId(), ':clang' => $clangId, ':ctype' => $ctypeId],
        );

        $ids = array_map(static fn(array $row): string => (string) $row['id'], $rows);

        return [
            'slice_count' => count($rows),
            // The id list, not just the count: a slice replaced by another of
            // the same module would otherwise look unchanged.
            'slice_ids' => implode(',', $ids),
        ];
    }

    /**
     * Media files and link targets named in the payload, plus the module itself.
     *
     * Applying a proposal whose image was deleted in the meantime would write a
     * filename pointing at nothing — visible on the website, and nothing in the
     * backend would flag it.
     *
     * @return list<string>
     */
    public function checkReferences(ChangeRequest $request): array
    {
        $target = $this->requireTarget($request->target);
        $problems = [];

        $moduleId = $target->getModuleId();
        if (null === $moduleId) {
            return [rex_i18n::rawMsg('ai_platform_change_err_slice_gone')];
        }
        if ([] === rex_sql::factory()->getArray('SELECT id FROM ' . rex::getTable('module') . ' WHERE id = :id', [':id' => $moduleId])) {
            $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_module_gone', $moduleId);
        }

        if (ChangeOperation::Delete === $request->operation) {
            return $problems;
        }

        $clangId = $target->getClangId() ?? rex_clang::getStartId();

        foreach ($request->effectivePayload()->toArray() as $slot => $value) {
            $slot = (string) $slot;
            $text = self::stringify($value);
            if (null === $text || '' === trim($text)) {
                continue;
            }

            if (1 === preg_match('/^media(\d+)$/', $slot)) {
                if (null === rex_media::get($text)) {
                    $problems[] = self::describeMissingMedia($slot, $text);
                }
                continue;
            }

            if (1 === preg_match('/^medialist(\d+)$/', $slot)) {
                foreach (explode(',', $text) as $filename) {
                    $filename = trim($filename);
                    if ('' !== $filename && null === rex_media::get($filename)) {
                        $problems[] = self::describeMissingMedia($slot, $filename);
                    }
                }
                continue;
            }

            if (1 === preg_match('/^link(\d+)$/', $slot)) {
                if (null === rex_article::get((int) $text, $clangId)) {
                    $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_article_gone', $slot, $text);
                }
                continue;
            }

            if (1 === preg_match('/^linklist(\d+)$/', $slot)) {
                foreach (explode(',', $text) as $articleId) {
                    $articleId = (int) trim($articleId);
                    if ($articleId > 0 && null === rex_article::get($articleId, $clangId)) {
                        $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_article_gone', $slot, (string) $articleId);
                    }
                }
            }
        }

        return $problems;
    }

    public function validate(ChangeRequest $request): void
    {
        $target = $this->requireTarget($request->target);

        $moduleId = $target->getModuleId();
        if (null === $moduleId) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_slice_gone'));
        }

        if (!ChangeService::isModuleAllowed($moduleId)) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_module_not_allowed', $moduleId));
        }

        $clangId = $target->getClangId() ?? 1;
        if (null === rex_article::get($target->getArticleId(), $clangId)) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_article_gone', $target->getArticleId()));
        }

        if (ChangeOperation::Create === $request->operation) {
            $this->assertModuleExists($moduleId);
        }

        if (ChangeOperation::Delete === $request->operation) {
            return;
        }

        $payload = $request->effectivePayload()->toArray();
        if ([] === $payload) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_no_fields'));
        }

        // Never merge a raw payload into an UPDATE: only content slots are
        // writable, so id, article_id, revision or createuser stay unreachable
        // from outside no matter what a payload claims.
        foreach (array_keys($payload) as $key) {
            if (!SlicePayload::isSlot((string) $key)) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_not_writable', (string) $key));
            }
        }

        $this->assertReferencesResolve($request);
    }

    public function canApprove(rex_user $user, ChangeRequest $request): bool
    {
        $target = $this->requireTarget($request->target);

        if ($user->isAdmin()) {
            return true;
        }

        $clangId = $target->getClangId();
        if (null !== $clangId && !$user->getComplexPerm('clang')->hasPerm($clangId)) {
            return false;
        }

        $article = rex_article::get($target->getArticleId(), $clangId ?? 1);
        if (null === $article) {
            return false;
        }

        if (!$user->getComplexPerm('structure')->hasCategoryPerm($article->getCategoryId())) {
            return false;
        }

        // Module rights count as much as category rights: an editor who may not
        // use a module in the backend must not approve one either.
        $moduleId = $target->getModuleId();

        return null !== $moduleId && $user->getComplexPerm('modules')->hasPerm($moduleId);
    }

    /**
     * Shows only the slots the payload touches.
     *
     * That is what keeps an unmapped diff usable: a proposal changing one slot
     * is one row, and the old value beside the new one identifies the field
     * well enough to decide, even when it is only called "value7".
     */
    public function diffFields(ChangeRequest $request, ?array $currentValues = null): array
    {
        $target = $this->requireTarget($request->target);
        $moduleId = $target->getModuleId();
        $before = $request->snapshotBefore ?? [];
        $note = null !== $moduleId && !ModuleFieldMap::hasMap($moduleId)
            ? rex_i18n::rawMsg('ai_platform_change_slice_unmapped_note')
            : null;

        if (ChangeOperation::Delete === $request->operation) {
            // Show what would be lost.
            $fields = [];
            foreach ($before as $slot => $value) {
                $text = self::stringify($value);
                if (null === $text || '' === $text) {
                    continue;
                }
                $fields[] = new DiffField(
                    (string) $slot,
                    $this->slotLabel($moduleId, (string) $slot),
                    $text,
                    null,
                    DiffField::KIND_REMOVED,
                    null === $currentValues ? null : self::stringify($currentValues[$slot] ?? null),
                    true,
                    $note,
                );
            }

            return $fields;
        }

        $fields = [];
        foreach ($request->effectivePayload()->toArray() as $slot => $after) {
            $fields[] = DiffField::compare(
                (string) $slot,
                $this->slotLabel($moduleId, (string) $slot),
                array_key_exists($slot, $before) ? self::stringify($before[$slot]) : null,
                self::stringify($after),
                null === $currentValues ? null : self::stringify($currentValues[$slot] ?? null),
                $note,
            );
        }

        return $fields;
    }

    public function apply(ChangeRequest $request): ApplyResult
    {
        $target = $this->requireTarget($request->target);

        return match ($request->operation) {
            ChangeOperation::Create => $this->applyCreate($request, $target),
            ChangeOperation::Update => $this->applyUpdate($request, $target),
            ChangeOperation::Delete => $this->applyDelete($target),
        };
    }

    private function applyCreate(ChangeRequest $request, SliceTarget $target): ApplyResult
    {
        $clangId = $target->getClangId() ?? 1;
        $ctypeId = $target->getCtypeId() ?? 1;
        $moduleId = $target->getModuleId();
        if (null === $moduleId) {
            return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_slice_gone'));
        }

        $data = [];
        foreach ($request->effectivePayload()->toArray() as $key => $value) {
            if (SlicePayload::isSlot((string) $key)) {
                $data[(string) $key] = $value;
            }
        }
        if (null !== $target->getPriority()) {
            $data['priority'] = $target->getPriority();
        }

        // addSlice() fires SLICE_ADDED itself and handles priorities.
        $message = rex_content_service::addSlice($target->getArticleId(), $clangId, $ctypeId, $moduleId, $data);

        // It does not return the new id, so read back the row it just inserted.
        $newId = $this->findLatestSliceId($target->getArticleId(), $clangId, $ctypeId);

        $this->stampArticle($target->getArticleId(), $clangId);

        return ApplyResult::ok(
            ['slice_id' => $newId],
            [['article_id' => $target->getArticleId(), 'clang_id' => $clangId]],
            ['' === $message ? 'Slice added.' : $message],
        );
    }

    private function applyUpdate(ChangeRequest $request, SliceTarget $target): ApplyResult
    {
        $sliceId = $target->getSliceId();
        $row = $target->loadRow();
        if (null === $sliceId || null === $row) {
            return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_slice_gone'));
        }

        $articleId = $target->getArticleId();
        $clangId = (int) $row['clang_id'];
        $ctypeId = (int) $row['ctype_id'];
        $moduleId = (int) $row['module_id'];
        $sliceRevision = (int) $row['revision'];
        $articleRevision = 0;

        $writable = [];
        foreach ($request->effectivePayload()->toArray() as $key => $value) {
            if (SlicePayload::isSlot((string) $key)) {
                $writable[(string) $key] = $value;
            }
        }
        if ([] === $writable) {
            return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_no_fields'));
        }

        $article = rex_article::get($articleId, $clangId);
        $categoryId = null === $article ? 0 : $article->getCategoryId();

        // PRE EP, same shape as pages/content.php — but only when a REDAXO user
        // is actually logged in.
        //
        // This used to fire unconditionally, with a comment saying it was safe
        // "because a backend user exists". That was true when the only way to
        // approve was the backend. `approveViaApi()` broke the assumption
        // without touching this line: a token approval has no REDAXO user, and
        // structure/history — a stock plugin listening on SLICE_UPDATE — calls
        // rex::requireUser(). The result was that every slice update approved
        // over the API died with "User object does not exist" and wrote
        // nothing, while article, category, media and metainfo went through.
        // Measured, not theoretical.
        //
        // Skipping the PRE EP costs the history snapshot for that one write and
        // nothing else — the write itself and all POST extension points still
        // run. A snapshot naming no user would be worse than none.
        $hasBackendUser = null !== rex::getUser();
        if ($hasBackendUser) {
            rex_extension::registerPoint(new rex_extension_point('SLICE_UPDATE', '', [
                'slice_id' => $sliceId,
                'article_id' => $articleId,
                'clang_id' => $clangId,
                'slice_revision' => $sliceRevision,
            ]));
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('article_slice'));
        $sql->setWhere(['id' => $sliceId]);
        foreach ($writable as $key => $value) {
            $sql->setValue($key, $value);
        }
        $sql->addGlobalUpdateFields();
        $sql->update();

        $info = '';
        $epParams = [
            'article_id' => $articleId,
            'clang' => $clangId,
            'function' => 'edit',
            'slice_id' => $sliceId,
            'page' => '',
            'ctype' => $ctypeId,
            'category_id' => $categoryId,
            'module_id' => $moduleId,
            'article_revision' => &$articleRevision,
            'slice_revision' => &$sliceRevision,
        ];

        $info = rex_extension::registerPoint(new rex_extension_point('SLICE_UPDATED', $info, $epParams));
        /* deprecated, still fired by the core */
        rex_extension::registerPoint(new rex_extension_point('STRUCTURE_CONTENT_SLICE_UPDATED', $info, $epParams));
        if (null !== $article) {
            rex_extension::registerPoint(new rex_extension_point_art_content_updated($article, 'slice_updated', $info));
        }

        $this->stampArticle($articleId, $clangId);

        return ApplyResult::ok(
            ['slice_id' => $sliceId],
            [['article_id' => $articleId, 'clang_id' => $clangId]],
        );
    }

    private function applyDelete(SliceTarget $target): ApplyResult
    {
        $sliceId = $target->getSliceId();
        $row = $target->loadRow();
        if (null === $sliceId || null === $row) {
            return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_slice_gone'));
        }

        $articleId = $target->getArticleId();
        $clangId = (int) $row['clang_id'];

        // rex_content_service::deleteSlice() fires SLICE_DELETE itself and
        // therefore cannot be used without a REDAXO user — same trap as the
        // update path above, and the reason slice deletions approved over the
        // API failed with "User object does not exist". The three steps it
        // performs are reproduced here so the PRE EP can stay conditional. The
        // update path already reproduces content.php for the same kind of
        // reason (there is no editSlice() in the core at all).
        if (null !== rex::getUser()) {
            rex_extension::registerPoint(new rex_extension_point('SLICE_DELETE', '', [
                'slice_id' => $sliceId,
                'article_id' => $articleId,
                'clang_id' => $clangId,
                'slice_revision' => (int) $row['revision'],
            ]));
        }

        rex_sql::factory()->setQuery(
            'DELETE FROM ' . rex::getTable('article_slice') . ' WHERE id = :id',
            [':id' => $sliceId],
        );

        // Same reorganisation deleteSlice() does: priorities stay gapless
        // within the article/language/ctype/revision the slice lived in.
        rex_sql_util::organizePriorities(
            rex::getTable('article_slice'),
            'priority',
            'article_id = ' . $articleId
            . ' AND clang_id = ' . $clangId
            . ' AND ctype_id = ' . (int) $row['ctype_id']
            . ' AND revision = ' . (int) $row['revision'],
            'priority',
        );

        $article = rex_article::get($articleId, $clangId);
        $epParams = [
            'article_id' => $articleId,
            'clang' => $clangId,
            'slice_id' => $sliceId,
            'ctype' => (int) $row['ctype_id'],
            'module_id' => (int) $row['module_id'],
            'category_id' => null === $article ? 0 : $article->getCategoryId(),
        ];
        $info = rex_extension::registerPoint(new rex_extension_point('SLICE_DELETED', '', $epParams));
        if (null !== $article) {
            rex_extension::registerPoint(new rex_extension_point_art_content_updated($article, 'slice_deleted', $info));
        }

        $this->stampArticle($articleId, $clangId);

        return ApplyResult::ok(
            [],
            [['article_id' => $articleId, 'clang_id' => $clangId]],
        );
    }

    /**
     * Touches the article's update stamp and drops its cache, the way both the
     * backend page and the api addon do after a slice write.
     */
    private function stampArticle(int $articleId, int $clangId): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('article'));
        $sql->setWhere(['id' => $articleId, 'clang_id' => $clangId]);
        $sql->addGlobalUpdateFields();
        $sql->update();

        rex_article_cache::delete($articleId, $clangId);

        rex_extension::registerPoint(new rex_extension_point('STRUCTURE_CONTENT_ARTICLE_UPDATED', '', [
            'id' => $articleId,
            'clang' => $clangId,
        ]));
    }

    private function slotLabel(?int $moduleId, string $slot): string
    {
        if (null === $moduleId) {
            return $slot;
        }

        return ModuleFieldMap::labelForSlot($moduleId, $slot) ?? $slot;
    }

    private function findLatestSliceId(int $articleId, int $clangId, int $ctypeId): ?int
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('article_slice')
            . ' WHERE article_id = :article AND clang_id = :clang AND ctype_id = :ctype'
            . ' ORDER BY id DESC LIMIT 1',
            [':article' => $articleId, ':clang' => $clangId, ':ctype' => $ctypeId],
        );

        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    }

    private function assertModuleExists(int $moduleId): void
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('module') . ' WHERE id = :id',
            [':id' => $moduleId],
        );

        if ([] === $rows) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_module_gone', $moduleId));
        }
    }

    private function requireTarget(TargetInterface $target): SliceTarget
    {
        if (!$target instanceof SliceTarget) {
            throw new ValidationException(sprintf('SliceHandler needs a SliceTarget, got %s.', $target::class));
        }

        return $target;
    }

    /**
     * Why a referenced media file is not there — and, in the common case, what to
     * do about it.
     *
     * "The file no longer exists" is the right answer when a slice points at
     * something an editor deleted. It is the wrong answer, and actively
     * misleading, for the mistake almost every caller makes first: proposing an
     * image and a slice that shows it in one go. The file never existed, it is
     * waiting in a pending proposal, and "no longer exists" sends the caller
     * looking for a deletion that never happened.
     *
     * So a filename that a staged upload has reserved gets its own message,
     * naming the ordering that actually works. This costs one indexed lookup on a
     * path that is already refusing the request.
     */
    private static function describeMissingMedia(string $slot, string $filename): string
    {
        $upload = (new PendingUploadStore())->findByFilename($filename);

        if (null !== $upload && null === $upload->consumedAt) {
            return rex_i18n::rawMsg('ai_platform_change_ref_media_pending', $slot, $filename);
        }

        return rex_i18n::rawMsg('ai_platform_change_ref_media_gone', $slot, $filename);
    }
}
