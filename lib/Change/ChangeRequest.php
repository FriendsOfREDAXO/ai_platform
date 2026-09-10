<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use DateTimeImmutable;
use rex_user;

/**
 * One proposed change, as persisted in rex_ai_change_request.
 *
 * Holds typed objects, not arrays: target and payload are hydrated by the
 * store, so everything downstream — validation, diffing, the backend UI, the
 * apply step — works against the same classes the proposer used. The raw
 * arrays exist only between json_decode() and fromArray().
 *
 * `payloadEdited` keeps a reviewer's correction separate from what the agent
 * originally proposed. The original is never overwritten, because it is the
 * only record of what the source actually suggested.
 *
 * Two fingerprints, not one. `baseHash` covers the target's own fields and
 * catches a human editing it in between. `contextHash` covers its surroundings —
 * sibling slices, category children — and is the only check a create operation
 * has, since it has no target to compare against.
 */
final class ChangeRequest
{
    /**
     * @param array<string, scalar|null>|null $snapshotBefore
     * @param array<string, mixed>|null $applyResult
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $type,
        public readonly ChangeOperation $operation,
        public readonly TargetInterface $target,
        public readonly PayloadInterface $payload,
        public readonly ?PayloadInterface $payloadEdited,
        public readonly ?array $snapshotBefore,
        public readonly string $baseHash,
        public readonly ?array $contextSnapshot,
        public readonly ?string $contextHash,
        public readonly string $reason,
        public readonly ChangeStatus $status,
        public readonly Source $source,
        public readonly ?int $changesetId = null,
        public readonly string $targetLabel = '',
        public readonly ?int $reviewedBy = null,
        public readonly ?DateTimeImmutable $reviewedAt = null,
        public readonly ?string $reviewNote = null,
        /** How the decision was reached: backend, api or auto. Null while undecided. */
        public readonly ?string $reviewedVia = null,
        public readonly ?DateTimeImmutable $appliedAt = null,
        public readonly ?array $applyResult = null,
        public readonly ?string $applyError = null,
        public readonly ?DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * The payload that will actually be written: the reviewer's correction
     * when there is one, otherwise the proposal as submitted.
     */
    public function effectivePayload(): PayloadInterface
    {
        return $this->payloadEdited ?? $this->payload;
    }

    public function wasEdited(): bool
    {
        return null !== $this->payloadEdited;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Label for lists — the denormalised one captured at proposal time, with
     * a live fallback. The stored label matters because a deleted target can
     * no longer describe itself, and a reviewer still needs to know what the
     * request was about.
     */
    public function label(): string
    {
        if ('' !== $this->targetLabel) {
            return $this->targetLabel;
        }

        return $this->target->describe();
    }

    public function reviewerName(): ?string
    {
        if (null === $this->reviewedBy) {
            return null;
        }

        return rex_user::get($this->reviewedBy)?->getLogin();
    }

    /**
     * Returns a copy with a new status and review metadata. The entity stays
     * immutable; the store persists what it is given.
     */
    public function withStatus(
        ChangeStatus $status,
        ?int $reviewedBy = null,
        ?string $reviewNote = null,
    ): self {
        return new self(
            $this->id,
            $this->type,
            $this->operation,
            $this->target,
            $this->payload,
            $this->payloadEdited,
            $this->snapshotBefore,
            $this->baseHash,
            $this->contextSnapshot,
            $this->contextHash,
            $this->reason,
            $status,
            $this->source,
            $this->changesetId,
            $this->targetLabel,
            $reviewedBy ?? $this->reviewedBy,
            null !== $reviewedBy ? new DateTimeImmutable() : $this->reviewedAt,
            $reviewNote ?? $this->reviewNote,
            $this->appliedAt,
            $this->applyResult,
            $this->applyError,
            $this->createdAt,
        );
    }

    public function withPayloadEdited(PayloadInterface $payload): self
    {
        return new self(
            $this->id,
            $this->type,
            $this->operation,
            $this->target,
            $this->payload,
            $payload,
            $this->snapshotBefore,
            $this->baseHash,
            $this->contextSnapshot,
            $this->contextHash,
            $this->reason,
            $this->status,
            $this->source,
            $this->changesetId,
            $this->targetLabel,
            $this->reviewedBy,
            $this->reviewedAt,
            $this->reviewNote,
            $this->appliedAt,
            $this->applyResult,
            $this->applyError,
            $this->createdAt,
        );
    }
}
