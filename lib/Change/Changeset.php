<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use DateTimeImmutable;
use rex_i18n;

/**
 * A package of change requests that belong together, so a reviewer can
 * decide about them in one go.
 *
 * Two identifiers, on purpose:
 *
 *  - `clientKey` is chosen by the submitter and lets it keep appending to
 *    its own package across several calls ("seo-run-2026-08").
 *  - `token` is generated here and is what everything else references.
 *
 * The unique index is on (source key, client key), so two different sources
 * can use the same friendly name without ever landing in each other's
 * package.
 *
 * `status` is derived from the member requests rather than set by hand —
 * see ChangeService::refreshChangesetStatus().
 */
final class Changeset
{
    public const STATUS_OPEN = 'open';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_PARTIAL = 'partially_applied';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        public readonly ?int $id,
        public readonly string $token,
        public readonly ?string $clientKey,
        public readonly string $title,
        public readonly string $description,
        public readonly string $status,
        public readonly Source $source,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly int $requestCount = 0,
        public readonly int $openCount = 0,
    ) {
    }

    public function displayTitle(): string
    {
        if ('' !== $this->title) {
            return $this->title;
        }

        return $this->clientKey ?? substr($this->token, 0, 8);
    }

    public function hasOpenRequests(): bool
    {
        return $this->openCount > 0;
    }

    public function cssClass(): string
    {
        return match ($this->status) {
            self::STATUS_APPLIED => 'label-success',
            self::STATUS_PARTIAL => 'label-warning',
            self::STATUS_REJECTED => 'label-default',
            default => 'label-primary',
        };
    }

    public function statusLabel(): string
    {
        return rex_i18n::rawMsg('ai_platform_changeset_status_' . $this->status);
    }
}
