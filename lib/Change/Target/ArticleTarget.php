<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Target;

use FriendsOfRedaxo\AiPlatform\Change\AbstractTarget;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use rex_article;
use rex_clang;
use rex_i18n;
use rex_url;

/**
 * Points at an article — its core fields only (name, priority, template,
 * status). Metainfo values live behind {@see MetaTarget::article()}, mirroring
 * the split the api addon makes between /structure/articles and
 * /structure/articles/{id}/metainfo.
 *
 * A create carries the parent category instead of an article id; the id is
 * only known after the change has been applied and is reported back in the
 * ApplyResult.
 */
final class ArticleTarget extends AbstractTarget
{
    private function __construct(
        private readonly ChangeOperation $operation,
        private readonly int $clangId,
        private readonly ?int $articleId = null,
        private readonly ?int $categoryId = null,
    ) {
    }

    public static function changeType(): string
    {
        return 'article';
    }

    public static function existing(int $articleId, int $clangId): self
    {
        return new self(
            ChangeOperation::Update,
            self::assertClang($clangId),
            self::assertPositive($articleId, 'Article id'),
        );
    }

    public static function forDeletion(int $articleId, int $clangId): self
    {
        return new self(
            ChangeOperation::Delete,
            self::assertClang($clangId),
            self::assertPositive($articleId, 'Article id'),
        );
    }

    /**
     * A new article in the given category. Category 0 is the site root.
     */
    public static function createIn(int $categoryId, int $clangId): self
    {
        if ($categoryId < 0) {
            throw new \InvalidArgumentException('Category id must not be negative.');
        }

        return new self(
            ChangeOperation::Create,
            self::assertClang($clangId),
            null,
            $categoryId,
        );
    }

    public function operation(): ChangeOperation
    {
        return $this->operation;
    }

    public function getArticleId(): ?int
    {
        return $this->articleId;
    }

    public function getClangId(): int
    {
        return $this->clangId;
    }

    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }

    /**
     * The category the change happens in — the parent for creates, the
     * article's own category otherwise. This is what permission checks run
     * against, so it must never guess: null means "cannot be determined",
     * and the handler must refuse rather than fall back to the root.
     */
    public function resolveCategoryId(): ?int
    {
        if (null !== $this->categoryId) {
            return $this->categoryId;
        }
        if (null === $this->articleId) {
            return null;
        }

        return rex_article::get($this->articleId, $this->clangId)?->getCategoryId();
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'clang_id' => $this->clangId,
            'article_id' => $this->articleId,
            'category_id' => $this->categoryId,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            ChangeOperation::from(self::requireString($data, 'operation')),
            self::requireInt($data, 'clang_id'),
            self::optionalInt($data, 'article_id'),
            self::optionalInt($data, 'category_id'),
        );
    }

    public function describe(): string
    {
        if (null !== $this->articleId) {
            return rex_i18n::rawMsg('ai_platform_change_target_article') . ' ' . self::describeArticle($this->articleId, $this->clangId);
        }

        $clang = rex_clang::get($this->clangId);
        $suffix = null === $clang ? '' : sprintf(' (%s)', $clang->getCode());

        return rex_i18n::rawMsg('ai_platform_change_target_new_article') . sprintf(' · %s #%d%s', rex_i18n::rawMsg('ai_platform_change_target_in_category'), (int) $this->categoryId, $suffix);
    }

    public function backendUrl(): ?string
    {
        $categoryId = $this->resolveCategoryId();
        if (null === $categoryId) {
            return null;
        }

        return rex_url::backendPage('structure', [
            'category_id' => $categoryId,
            'clang' => $this->clangId,
        ]);
    }
}
