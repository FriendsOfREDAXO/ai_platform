<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Target;

use FriendsOfRedaxo\AiPlatform\Change\AbstractTarget;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use rex;
use rex_article;
use rex_clang;
use rex_i18n;
use rex_sql;
use rex_url;

/**
 * Points at a content slice.
 *
 * Updates and deletions address an existing slice by id and carry the
 * article id alongside — the handler verifies the pair, so a request cannot
 * be redirected to a slice in a different article by tampering with the
 * stored id alone. Creates address the insertion point instead: article,
 * language, ctype and module.
 *
 * `module_id` lives on the target rather than the payload because the
 * handler needs it to resolve the module's field layout before it can
 * validate the payload at all.
 */
final class SliceTarget extends AbstractTarget
{
    private function __construct(
        private readonly ChangeOperation $operation,
        private readonly int $articleId,
        private readonly ?int $sliceId = null,
        private readonly ?int $clangId = null,
        private readonly ?int $moduleId = null,
        private readonly ?int $ctypeId = null,
        private readonly ?int $priority = null,
    ) {
    }

    public static function changeType(): string
    {
        return 'slice';
    }

    /**
     * An existing slice, to be changed.
     */
    public static function existing(int $sliceId, int $articleId): self
    {
        return new self(
            ChangeOperation::Update,
            self::assertPositive($articleId, 'Article id'),
            self::assertPositive($sliceId, 'Slice id'),
        );
    }

    /**
     * An existing slice, to be removed.
     */
    public static function forDeletion(int $sliceId, int $articleId): self
    {
        return new self(
            ChangeOperation::Delete,
            self::assertPositive($articleId, 'Article id'),
            self::assertPositive($sliceId, 'Slice id'),
        );
    }

    /**
     * A new slice in the given article.
     *
     * @param int|null $priority 1-based position, null appends at the end
     */
    public static function createIn(
        int $articleId,
        int $clangId,
        int $moduleId,
        int $ctypeId = 1,
        ?int $priority = null,
    ): self {
        return new self(
            ChangeOperation::Create,
            self::assertPositive($articleId, 'Article id'),
            null,
            self::assertClang($clangId),
            self::assertPositive($moduleId, 'Module id'),
            self::assertPositive($ctypeId, 'Ctype id'),
            null === $priority ? null : self::assertPositive($priority, 'Priority'),
        );
    }

    public function operation(): ChangeOperation
    {
        return $this->operation;
    }

    public function getArticleId(): int
    {
        return $this->articleId;
    }

    public function getSliceId(): ?int
    {
        return $this->sliceId;
    }

    public function getCtypeId(): ?int
    {
        return $this->ctypeId;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    /**
     * The language of the slice. Known upfront for creates; for existing
     * slices it is read from the row, because that is the authoritative
     * value and callers should not have to repeat it.
     */
    public function getClangId(): ?int
    {
        if (null !== $this->clangId) {
            return $this->clangId;
        }

        $row = $this->loadRow();

        return null === $row ? null : (int) $row['clang_id'];
    }

    /**
     * The module of the slice — from the target for creates, from the row
     * for existing slices.
     */
    public function getModuleId(): ?int
    {
        if (null !== $this->moduleId) {
            return $this->moduleId;
        }

        $row = $this->loadRow();

        return null === $row ? null : (int) $row['module_id'];
    }

    /**
     * The slice row, or null when the slice no longer exists or does not
     * belong to the article recorded in this target.
     *
     * @return array<string, mixed>|null
     */
    public function loadRow(): ?array
    {
        if (null === $this->sliceId) {
            return null;
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('article_slice') . ' WHERE id = :id AND article_id = :article',
            [':id' => $this->sliceId, ':article' => $this->articleId],
        );

        return $rows[0] ?? null;
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'article_id' => $this->articleId,
            'slice_id' => $this->sliceId,
            'clang_id' => $this->clangId,
            'module_id' => $this->moduleId,
            'ctype_id' => $this->ctypeId,
            'priority' => $this->priority,
        ];
    }

    public static function fromArray(array $data): static
    {
        $operation = ChangeOperation::from(self::requireString($data, 'operation'));

        return new self(
            $operation,
            self::requireInt($data, 'article_id'),
            self::optionalInt($data, 'slice_id'),
            self::optionalInt($data, 'clang_id'),
            self::optionalInt($data, 'module_id'),
            self::optionalInt($data, 'ctype_id'),
            self::optionalInt($data, 'priority'),
        );
    }

    public function describe(): string
    {
        $clangId = $this->getClangId() ?? rex_clang::getStartId();
        $label = rex_i18n::rawMsg('ai_platform_change_target_article') . ' ' . self::describeArticle($this->articleId, $clangId);

        $moduleId = $this->getModuleId();
        $moduleName = null === $moduleId ? null : self::describeModule($moduleId);

        if (null !== $this->sliceId) {
            $label .= sprintf(' · Slice #%d', $this->sliceId);
        } else {
            $label .= ' · ' . rex_i18n::rawMsg('ai_platform_change_target_new_slice');
        }

        if (null !== $moduleName) {
            $label .= sprintf(' (%s)', $moduleName);
        }

        return $label;
    }

    public function backendUrl(): ?string
    {
        $article = rex_article::get($this->articleId, $this->getClangId() ?? rex_clang::getStartId());
        if (null === $article) {
            return null;
        }

        return rex_url::backendPage('content/edit', [
            'article_id' => $this->articleId,
            'clang' => $this->getClangId() ?? rex_clang::getStartId(),
            'ctype' => $this->getCtypeId() ?? 1,
        ]);
    }
}
