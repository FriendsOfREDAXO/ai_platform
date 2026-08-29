<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Payload;

use FriendsOfRedaxo\AiPlatform\Change\AbstractPatchPayload;
use InvalidArgumentException;

/**
 * Core fields of an article, and nothing else.
 *
 * These four are exactly what rex_article_service writes — `name`,
 * `template_id`, `priority` through addArticle/editArticle, and `status`
 * through the separate articleStatus() call. Metainfo goes through
 * MetaPayload.
 *
 * Two traps live behind this class and are handled in ArticleHandler, not
 * here:
 *
 *  1. editArticle() requires `name` on every call and writes name,
 *     template_id and priority unconditionally — it does not patch. The
 *     handler therefore fills unset fields from the stored snapshot.
 *  2. editArticle() silently rewrites template_id to an allowed template
 *     (or 0) when the requested one is not permitted for the category. The
 *     handler validates that upfront, because a diff that shows template A
 *     while B gets written is worse than no diff at all.
 */
final class ArticlePayload extends AbstractPatchPayload
{
    public function name(string $name): self
    {
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidArgumentException('Article name must not be empty.');
        }

        return $this->put('name', $name);
    }

    /**
     * 1-based sort position within the category. REDAXO clamps values below
     * 1 to 1, so anything smaller is rejected here instead of being
     * quietly corrected.
     */
    public function priority(int $priority): self
    {
        if ($priority < 1) {
            throw new InvalidArgumentException('Article priority must be 1 or greater.');
        }

        return $this->put('priority', $priority);
    }

    /**
     * Template id, or 0 for "no template".
     */
    public function templateId(int $templateId): self
    {
        if ($templateId < 0) {
            throw new InvalidArgumentException('Template id must not be negative.');
        }

        return $this->put('template_id', $templateId);
    }

    /**
     * 0 = offline, 1 = online. Applied via rex_article_service::articleStatus().
     */
    public function status(int $status): self
    {
        if (0 !== $status && 1 !== $status) {
            throw new InvalidArgumentException('Article status must be 0 (offline) or 1 (online).');
        }

        return $this->put('status', $status);
    }

    /**
     * Fields written through editArticle(), in contrast to `status` which
     * takes its own service call.
     *
     * @return list<string>
     */
    public static function serviceFields(): array
    {
        return ['name', 'template_id', 'priority'];
    }

    /**
     * @return list<string>
     */
    public static function allFields(): array
    {
        return ['name', 'template_id', 'priority', 'status'];
    }
}
