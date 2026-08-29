<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Handler;

use FriendsOfRedaxo\AiPlatform\Change\AbstractHandler;
use FriendsOfRedaxo\AiPlatform\Change\ApplyResult;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\Payload\ArticlePayload;
use FriendsOfRedaxo\AiPlatform\Change\Target\ArticleTarget;
use FriendsOfRedaxo\AiPlatform\Change\TargetInterface;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;
use rex;
use rex_article;
use rex_article_service;
use rex_category;
use rex_i18n;
use rex_sql;
use rex_template;
use rex_user;
use Throwable;

use function array_key_exists;
use function count;

/**
 * Articles — core fields only. Metainfo goes through MetaHandler.
 *
 * Two quirks of rex_article_service shape this handler, and neither can be
 * hidden in the payload class:
 *
 *  1. editArticle() is not a patch. It requires `name` on every call and
 *     writes name, template_id and priority unconditionally. Fields the
 *     proposal did not set are therefore filled from the stored snapshot —
 *     otherwise a proposal that only changes the priority would blank the
 *     article name.
 *  2. editArticle() silently rewrites template_id to an allowed template (or
 *     0) when the requested one is not permitted for the category. That is
 *     checked upfront here, because a diff promising template A while B gets
 *     written is worse than no diff at all.
 */
final class ArticleHandler extends AbstractHandler
{
    public function getType(): string
    {
        return 'article';
    }

    public function targetClass(): string
    {
        return ArticleTarget::class;
    }

    public function payloadClass(): string
    {
        return ArticlePayload::class;
    }

    public function readCurrent(TargetInterface $target): ?array
    {
        $target = $this->requireTarget($target);
        $articleId = $target->getArticleId();
        if (null === $articleId) {
            return null;
        }

        $article = rex_article::get($articleId, $target->getClangId());
        if (null === $article) {
            return null;
        }

        return [
            'name' => $article->getName(),
            'template_id' => (int) $article->getTemplateId(),
            'priority' => (int) $article->getValue('priority'),
            'status' => (int) $article->getValue('status'),
        ];
    }

    /**
     * The siblings in the article's category.
     *
     * Relevant because priority is a position: a proposal to move an article to
     * slot 3 means something else once two siblings have appeared or vanished.
     */
    public function readContext(TargetInterface $target): ?array
    {
        $target = $this->requireTarget($target);
        $categoryId = $target->resolveCategoryId();
        if (null === $categoryId) {
            return null;
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('article')
            . ' WHERE parent_id = :parent AND clang_id = :clang AND startarticle = 0 ORDER BY priority, id',
            [':parent' => $categoryId, ':clang' => $target->getClangId()],
        );

        return [
            'sibling_count' => count($rows),
            'sibling_ids' => implode(',', array_map(static fn(array $r): string => (string) $r['id'], $rows)),
        ];
    }

    /**
     * Template and target category.
     *
     * @return list<string>
     */
    public function checkReferences(ChangeRequest $request): array
    {
        $target = $this->requireTarget($request->target);
        $payload = $request->effectivePayload()->toArray();
        $problems = [];

        $categoryId = $target->resolveCategoryId();
        if (null !== $categoryId && 0 !== $categoryId && null === rex_category::get($categoryId, $target->getClangId())) {
            $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_category_gone', $categoryId);
        }

        if (array_key_exists('template_id', $payload)) {
            $templateId = (int) $payload['template_id'];
            if (0 !== $templateId) {
                // rex_template has no static get(); existence is a table lookup.
                $exists = [] !== rex_sql::factory()->getArray(
                    'SELECT id FROM ' . rex::getTable('template') . ' WHERE id = :id',
                    [':id' => $templateId],
                );
                if (!$exists) {
                    $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_template_gone', $templateId);
                } elseif (null !== $categoryId) {
                    $allowed = rex_template::getTemplatesForCategory($categoryId);
                    if (!isset($allowed[$templateId])) {
                        $problems[] = rex_i18n::rawMsg(
                            'ai_platform_change_err_template_not_allowed',
                            $templateId,
                            [] === $allowed ? '-' : implode(', ', array_keys($allowed)),
                        );
                    }
                }
            }
        }

        return $problems;
    }

    public function validate(ChangeRequest $request): void
    {
        $target = $this->requireTarget($request->target);
        $payload = $request->effectivePayload()->toArray();

        self::filterToAllowed($payload, ArticlePayload::allFields());

        if (ChangeOperation::Delete === $request->operation) {
            $this->assertExists($target);

            return;
        }

        if ([] === $payload) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_no_fields'));
        }

        $categoryId = $target->resolveCategoryId();
        if (null === $categoryId) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_category_unresolved'));
        }

        if (ChangeOperation::Create === $request->operation) {
            if (!array_key_exists('name', $payload)) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_name_required'));
            }
        } else {
            $this->assertExists($target);
        }

        // Templates and the target category are checked as references, so the
        // same finding shows up in the inbox instead of only on approval.
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

        $categoryId = $target->resolveCategoryId();
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
            if ('priority' !== $field->key) {
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
        return rex_i18n::rawMsg('ai_platform_change_field_article_' . $key);
    }

    public function apply(ChangeRequest $request): ApplyResult
    {
        $target = $this->requireTarget($request->target);
        $payload = $request->effectivePayload()->toArray();

        try {
            return match ($request->operation) {
                ChangeOperation::Create => $this->applyCreate($target, $payload),
                ChangeOperation::Update => $this->applyUpdate($target, $payload, $request),
                ChangeOperation::Delete => $this->applyDelete($target),
            };
        } catch (Throwable $e) {
            return ApplyResult::failed($e->getMessage());
        }
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function applyCreate(ArticleTarget $target, array $payload): ApplyResult
    {
        $categoryId = (int) $target->getCategoryId();

        $data = [
            'category_id' => $categoryId,
            'name' => (string) ($payload['name'] ?? ''),
            'priority' => (int) ($payload['priority'] ?? 1),
            'template_id' => (int) ($payload['template_id'] ?? 0),
        ];

        $message = rex_article_service::addArticle($data);

        // addArticle() creates the article in every language and does not
        // return the id, so look it up by the values we just wrote.
        $newId = $this->findNewArticleId($categoryId, $data['name'], $target->getClangId());

        if (null !== $newId && array_key_exists('status', $payload) && 1 === (int) $payload['status']) {
            rex_article_service::articleStatus($newId, $target->getClangId(), 1);
        }

        return ApplyResult::ok(
            ['article_id' => $newId],
            null === $newId ? [] : [['article_id' => $newId, 'clang_id' => $target->getClangId()]],
            [$message],
        );
    }

    /**
     * @param array<string, scalar|null> $payload
     */
    private function applyUpdate(ArticleTarget $target, array $payload, ChangeRequest $request): ApplyResult
    {
        $articleId = (int) $target->getArticleId();
        $clangId = $target->getClangId();
        $snapshot = $request->snapshotBefore ?? [];

        $messages = [];

        // editArticle() overwrites all three fields, so anything the proposal
        // left alone has to come from the snapshot.
        $serviceFields = array_intersect_key($payload, array_flip(ArticlePayload::serviceFields()));
        if ([] !== $serviceFields) {
            $data = [
                'name' => (string) ($payload['name'] ?? $snapshot['name'] ?? ''),
                'template_id' => (int) ($payload['template_id'] ?? $snapshot['template_id'] ?? 0),
                'priority' => (int) ($payload['priority'] ?? $snapshot['priority'] ?? 1),
            ];

            if ('' === $data['name']) {
                return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_name_required'));
            }

            $messages[] = rex_article_service::editArticle($articleId, $clangId, $data);
        }

        // Status has its own service call — it is not part of editArticle().
        if (array_key_exists('status', $payload)) {
            $messages[] = rex_article_service::articleStatus($articleId, $clangId, (int) $payload['status']);
        }

        return ApplyResult::ok(
            ['article_id' => $articleId],
            [['article_id' => $articleId, 'clang_id' => $clangId]],
            array_values(array_filter(array_map('strval', $messages))),
        );
    }

    private function applyDelete(ArticleTarget $target): ApplyResult
    {
        $articleId = (int) $target->getArticleId();
        $message = rex_article_service::deleteArticle($articleId);

        return ApplyResult::ok(['article_id' => $articleId], [], [(string) $message]);
    }

    private function findNewArticleId(int $categoryId, string $name, int $clangId): ?int
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('article')
            . ' WHERE parent_id = :parent AND name = :name AND clang_id = :clang AND startarticle = 0'
            . ' ORDER BY id DESC LIMIT 1',
            [':parent' => $categoryId, ':name' => $name, ':clang' => $clangId],
        );

        return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    }

    private function assertExists(ArticleTarget $target): void
    {
        $articleId = $target->getArticleId();
        if (null === $articleId || null === rex_article::get($articleId, $target->getClangId())) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_article_gone', (int) $articleId));
        }
    }

    private function requireTarget(TargetInterface $target): ArticleTarget
    {
        if (!$target instanceof ArticleTarget) {
            throw new ValidationException(sprintf('ArticleHandler needs an ArticleTarget, got %s.', $target::class));
        }

        return $target;
    }
}
