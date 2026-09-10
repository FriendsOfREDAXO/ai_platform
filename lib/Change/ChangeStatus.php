<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use rex_i18n;

/**
 * Lifecycle of a single change request.
 *
 *   Pending ──approve──► Approved ──apply──► Applied
 *      │                     │
 *      │                     └──error──────► Failed
 *      ├──reject───────────────────────────► Rejected
 *      ├──withdrawn by its own submitter ──► Withdrawn
 *      ├──newer request for same target ───► Superseded
 *      └──retention / target gone ─────────► Expired
 *
 * Approved is a real, persisted state and not just a moment in time: large
 * changesets are applied in batches across several requests (and, later, by
 * a cronjob worker), so a request can sit approved-but-not-yet-written.
 */
enum ChangeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Superseded = 'superseded';
    case Expired = 'expired';
    /**
     * Taken back by whoever submitted it, before anyone decided.
     *
     * Deliberately not the same as Rejected: rejected means an editor looked at
     * it and said no, and that judgement is worth keeping distinct from "the
     * submitter noticed its own mistake". An agent that can withdraw does not
     * leave its misfires in someone else's inbox — and nothing is deleted, the
     * row stays with this status.
     */
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return rex_i18n::rawMsg('ai_platform_change_status_' . $this->value);
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::Pending => 'label-warning',
            self::Approved => 'label-primary',
            self::Applied => 'label-success',
            self::Rejected => 'label-default',
            self::Failed => 'label-danger',
            self::Superseded, self::Expired, self::Withdrawn => 'label-default',
        };
    }

    /**
     * Whether a reviewer can still act on a request in this state.
     */
    public function isOpen(): bool
    {
        return self::Pending === $this || self::Approved === $this;
    }

    /**
     * Whether the request has reached a state that no longer changes on its own.
     */
    public function isFinal(): bool
    {
        return !$this->isOpen();
    }

    /**
     * States that count towards per-source flood limits.
     *
     * @return list<self>
     */
    public static function openStates(): array
    {
        return [self::Pending, self::Approved];
    }
}
