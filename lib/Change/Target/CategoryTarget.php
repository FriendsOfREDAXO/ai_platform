<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Target;

use FriendsOfRedaxo\AiPlatform\Change\AbstractTarget;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use InvalidArgumentException;
use rex_category;
use rex_clang;
use rex_i18n;
use rex_url;

/**
 * Points at a category — core fields only (catname, catpriority, status).
 *
 * Kept separate from {@see ArticleTarget} on purpose: rex_category_service
 * and rex_article_service disagree in ways that must not be papered over.
 * The category service patches (it checks isset per field) and uses the
 * `catname` / `catpriority` columns, while the article service demands a
 * name on every edit and writes `name` / `priority`. One shared class would
 * hide that difference and produce silent data loss on partial updates.
 */
final class CategoryTarget extends AbstractTarget
{
    private function __construct(
        private readonly ChangeOperation $operation,
        private readonly int $clangId,
        private readonly ?int $categoryId = null,
        private readonly ?int $parentId = null,
    ) {
    }

    public static function changeType(): string
    {
        return 'category';
    }

    public static function existing(int $categoryId, int $clangId): self
    {
        return new self(
            ChangeOperation::Update,
            self::assertClang($clangId),
            self::assertPositive($categoryId, 'Category id'),
        );
    }

    public static function forDeletion(int $categoryId, int $clangId): self
    {
        return new self(
            ChangeOperation::Delete,
            self::assertClang($clangId),
            self::assertPositive($categoryId, 'Category id'),
        );
    }

    /**
     * A new category below the given parent. Parent 0 is the site root.
     */
    public static function createIn(int $parentId, int $clangId): self
    {
        if ($parentId < 0) {
            throw new InvalidArgumentException('Parent category id must not be negative.');
        }

        return new self(
            ChangeOperation::Create,
            self::assertClang($clangId),
            null,
            $parentId,
        );
    }

    public function operation(): ChangeOperation
    {
        return $this->operation;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    public function getClangId(): int
    {
        return $this->clangId;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    /**
     * The category permissions are checked against: the category itself for
     * updates, the parent for creates.
     */
    public function resolvePermCategoryId(): ?int
    {
        return $this->categoryId ?? $this->parentId;
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'clang_id' => $this->clangId,
            'category_id' => $this->categoryId,
            'parent_id' => $this->parentId,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            ChangeOperation::from(self::requireString($data, 'operation')),
            self::requireInt($data, 'clang_id'),
            self::optionalInt($data, 'category_id'),
            self::optionalInt($data, 'parent_id'),
        );
    }

    public function describe(): string
    {
        $clang = rex_clang::get($this->clangId);
        $suffix = null === $clang ? '' : sprintf(' (%s)', $clang->getCode());

        if (null !== $this->categoryId) {
            $category = rex_category::get($this->categoryId, $this->clangId);
            $name = null === $category ? '' : sprintf(' »%s«', $category->getName());

            return rex_i18n::rawMsg('ai_platform_change_target_category') . sprintf(' #%d%s%s', $this->categoryId, $name, $suffix);
        }

        return rex_i18n::rawMsg('ai_platform_change_target_new_category') . sprintf(' · %s #%d%s', rex_i18n::rawMsg('ai_platform_change_target_in_category'), (int) $this->parentId, $suffix);
    }

    public function backendUrl(): ?string
    {
        $id = $this->resolvePermCategoryId();
        if (null === $id) {
            return null;
        }

        return rex_url::backendPage('structure', [
            'category_id' => $id,
            'clang' => $this->clangId,
        ]);
    }
}
