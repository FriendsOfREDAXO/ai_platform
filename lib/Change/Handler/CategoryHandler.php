<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Handler;

use FriendsOfRedaxo\AiPlatform\Change\AbstractHandler;
use FriendsOfRedaxo\AiPlatform\Change\ApplyResult;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\Payload\CategoryPayload;
use FriendsOfRedaxo\AiPlatform\Change\Target\CategoryTarget;
use FriendsOfRedaxo\AiPlatform\Change\TargetInterface;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;
use rex;
use rex_category;
use rex_category_service;
use rex_i18n;
use rex_sql;
use rex_user;
use Throwable;

use function array_key_exists;
use function count;

/**
 * Categories — core fields only. Metainfo goes through MetaHandler.
 *
 * Separate from ArticleHandler because rex_category_service behaves
 * differently from rex_article_service: editCategory() genuinely patches (it
 * checks isset per field) and writes the `catname` / `catpriority` columns. No
 * snapshot back-fill is needed here, and adding one would change behaviour
 * rather than preserve it.
 */
final class CategoryHandler extends AbstractHandler
{
    public function getType(): string
    {
        return 'category';
    }

    public function targetClass(): string
    {
        return CategoryTarget::class;
    }

    public function payloadClass(): string
    {
        return CategoryPayload::class;
    }

    public function readCurrent(TargetInterface $target): ?array
    {
        $target = $this->requireTarget($target);
        $categoryId = $target->getCategoryId();
        if (null === $categoryId) {
            return null;
        }

        $category = rex_category::get($categoryId, $target->getClangId());
        if (null === $category) {
            return null;
        }

        return [
            'catname' => (string) $category->getValue('catname'),
            'catpriority' => (int) $category->getValue('catpriority'),
            'status' => (int) $category->getValue('status'),
        ];
    }

    /**
     * The sibling categories, for the same reason as with articles: catpriority
     * is a position, not a value.
     */
    public function readContext(TargetInterface $target): ?array
    {
        $target = $this->requireTarget($target);
        $parentId = $target->getParentId();

        if (null === $parentId) {
            $category = null === $target->getCategoryId()
                ? null
                : rex_category::get($target->getCategoryId(), $target->getClangId());
            $parentId = $category?->getParentId();
        }

        if (null === $parentId) {
            return null;
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('article')
            . ' WHERE parent_id = :parent AND clang_id = :clang AND startarticle = 1 ORDER BY catpriority, id',
            [':parent' => $parentId, ':clang' => $target->getClangId()],
        );

        return [
            'sibling_count' => count($rows),
            'sibling_ids' => implode(',', array_map(static fn(array $r): string => (string) $r['id'], $rows)),
        ];
    }

    /**
     * The parent category, for a create.
     *
     * @return list<string>
     */
    public function checkReferences(ChangeRequest $request): array
    {
        $target = $this->requireTarget($request->target);
        $parentId = $target->getParentId();

        if (null !== $parentId && 0 !== $parentId && null === rex_category::get($parentId, $target->getClangId())) {
            return [rex_i18n::rawMsg('ai_platform_change_ref_category_gone', $parentId)];
        }

        return [];
    }

    public function validate(ChangeRequest $request): void
    {
        $target = $this->requireTarget($request->target);
        $payload = $request->effectivePayload()->toArray();

        self::filterToAllowed($payload, CategoryPayload::allFields());

        if (ChangeOperation::Delete === $request->operation) {
            $this->assertExists($target);

            return;
        }

        if ([] === $payload) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_no_fields'));
        }

        if (ChangeOperation::Create === $request->operation) {
            if (!array_key_exists('catname', $payload)) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_name_required'));
            }

            return;
        }

        $this->assertExists($target);
        $this->assertReferencesResolve($request);
    }

    public function canApprove(rex_user $user, ChangeRequest $request): bool
    {
        $target = $this->requireTarget($request->target);

        if ($user->isAdmin()) {
            return true;
        }

        if (!$user->getComplexPerm('clang')->hasPerm($target->getClangId())) {
            return false;
        }

        $categoryId = $target->resolvePermCategoryId();
        if (null === $categoryId) {
            return false;
        }

        return $user->getComplexPerm('structure')->hasCategoryPerm($categoryId);
    }

    /**
     * Marks the priority field as a relative position.
     *
     * REDAXO renumbers all siblings gapless on write, so a request asking for
     * position 6 in a category holding three items ends up at 3. Saying so in
     * the diff keeps the reviewer from reading it as a broken write.
     */
    public function diffFields(ChangeRequest $request, ?array $currentValues = null): array
    {
        $fields = parent::diffFields($request, $currentValues);

        foreach ($fields as $index => $field) {
            if ('catpriority' !== $field->key) {
                continue;
            }
            $fields[$index] = new \FriendsOfRedaxo\AiPlatform\Change\Diff\DiffField(
                $field->key,
                $field->label,
                $field->before,
                $field->after,
                $field->kind,
                $field->current,
                $field->longText,
                rex_i18n::rawMsg('ai_platform_change_note_relative_priority'),
            );
        }

        return $fields;
    }

    protected function fieldLabel(string $key, ChangeRequest $request): string
    {
        return rex_i18n::rawMsg('ai_platform_change_field_category_' . $key);
    }

    public function apply(ChangeRequest $request): ApplyResult
    {
        $target = $this->requireTarget($request->target);
        $payload = $request->effectivePayload()->toArray();

        try {
            return match ($request->operation) {
                ChangeOperation::Create => $this->applyCreate($target, $payload),
                ChangeOperation::Update => $this->applyUpdate($target, $payload),
                ChangeOperation::Delete => $this->applyDelete($target),
            };
        } catch (Throwable $e) {
            return ApplyResult::failed($e->getMessage());
        }
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function applyCreate(CategoryTarget $target, array $payload): ApplyResult
    {
        $parentId = (int) $target->getParentId();

        $message = rex_category_service::addCategory($parentId, [
            'catname' => (string) ($payload['catname'] ?? ''),
            'catpriority' => (int) ($payload['catpriority'] ?? 1),
        ]);

        $newId = $this->findNewCategoryId($parentId, (string) ($payload['catname'] ?? ''), $target->getClangId());

        if (null !== $newId && array_key_exists('status', $payload) && 1 === (int) $payload['status']) {
            rex_category_service::categoryStatus($newId, $target->getClangId(), 1);
        }

        return ApplyResult::ok(['category_id' => $newId], [], [(string) $message]);
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function applyUpdate(CategoryTarget $target, array $payload): ApplyResult
    {
        $categoryId = (int) $target->getCategoryId();
        $clangId = $target->getClangId();
        $messages = [];

        // editCategory() patches per field, so only what was proposed is passed.
        $data = array_intersect_key($payload, array_flip(CategoryPayload::serviceFields()));
        if ([] !== $data) {
            $messages[] = rex_category_service::editCategory($categoryId, $clangId, $data);
        }

        if (array_key_exists('status', $payload)) {
            $messages[] = rex_category_service::categoryStatus($categoryId, $clangId, (int) $payload['status']);
        }

        return ApplyResult::ok(
            ['category_id' => $categoryId],
            [['article_id' => $categoryId, 'clang_id' => $clangId]],
            array_values(array_filter(array_map('strval', $messages))),
        );
    }

    private function applyDelete(CategoryTarget $target): ApplyResult
    {
        $categoryId = (int) $target->getCategoryId();
        $message = rex_category_service::deleteCategory($categoryId);

        return ApplyResult::ok(['category_id' => $categoryId], [], [(string) $message]);
    }

    private function findNewCategoryId(int $parentId, string $name, int $clangId): ?int
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('article')
            . ' WHERE parent_id = :parent AND catname = :name AND clang_id = :clang AND startarticle = 1'
            . ' ORDER BY id DESC LIMIT 1',
            [':parent' => $parentId, ':name' => $name, ':clang' => $clangId],
        );

        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    }

    private function assertExists(CategoryTarget $target): void
    {
        $categoryId = $target->getCategoryId();
        if (null === $categoryId || null === rex_category::get($categoryId, $target->getClangId())) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_category_gone', (int) $categoryId));
        }
    }

    private function requireTarget(TargetInterface $target): CategoryTarget
    {
        if (!$target instanceof CategoryTarget) {
            throw new ValidationException(sprintf('CategoryHandler needs a CategoryTarget, got %s.', $target::class));
        }

        return $target;
    }
}
