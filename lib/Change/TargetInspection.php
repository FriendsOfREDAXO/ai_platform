<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use rex_i18n;

/**
 * What has happened to a change request's surroundings since it was proposed.
 *
 * A proposal sits in the inbox for hours or days. In that time the world moves,
 * and it moves in more than one way — which is why this is not a single "stale"
 * boolean:
 *
 *  - **gone**: the target itself was deleted. Nothing to compare, nothing to
 *    write. Terminal.
 *  - **changed**: the target's own fields differ from the snapshot taken at
 *    proposal time. Applying would overwrite whatever a human did in between.
 *  - **brokenReferences**: the proposal points at something that no longer
 *    exists — a media file, a link target, a module, a template, a related
 *    dataset. Applying would write a dangling reference, so this blocks
 *    regardless of policy: there is no version of "apply anyway" that produces
 *    correct data.
 *  - **contextChanged**: the target is untouched but its surroundings are not.
 *    An article that had no slices now has three; a category gained children.
 *    This matters most for create operations, which have no target to
 *    fingerprint at all — without it, a "new slice at position 1" proposal from
 *    last week would silently land in front of content nobody had written yet.
 *    A warning, not a block: the proposal is not wrong, only possibly
 *    misplaced.
 *
 * The four are independent. A request can be both changed and carry a broken
 * reference, and the reviewer should see both.
 */
final class TargetInspection
{
    /**
     * @param array<string, scalar|null>|null $currentValues Present state of the target
     * @param list<string> $changedFields Field names whose value moved since the proposal
     * @param list<string> $brokenReferences Human-readable descriptions of dangling references
     * @param list<string> $contextChanges Human-readable descriptions of changed surroundings
     */
    public function __construct(
        public readonly bool $gone = false,
        public readonly bool $changed = false,
        public readonly bool $contextChanged = false,
        public readonly ?array $currentValues = null,
        public readonly array $changedFields = [],
        public readonly array $brokenReferences = [],
        public readonly array $contextChanges = [],
    ) {
    }

    public static function ok(?array $currentValues = null): self
    {
        return new self(currentValues: $currentValues);
    }

    public static function targetGone(): self
    {
        return new self(gone: true);
    }

    public function hasBrokenReferences(): bool
    {
        return [] !== $this->brokenReferences;
    }

    /**
     * Whether anything at all is off.
     */
    public function isClean(): bool
    {
        return !$this->gone && !$this->changed && !$this->contextChanged && !$this->hasBrokenReferences();
    }

    /**
     * Whether approval must be refused without an explicit override.
     *
     * A broken reference is not overridable — see the class comment.
     */
    public function blocksApproval(bool $blockOnChanged): bool
    {
        if ($this->gone || $this->hasBrokenReferences()) {
            return true;
        }

        return $this->changed && $blockOnChanged;
    }

    /**
     * Whether an override switch can help at all.
     */
    public function isOverridable(): bool
    {
        return !$this->gone && !$this->hasBrokenReferences() && $this->changed;
    }

    /**
     * Short label for the list view, or null when everything is in order.
     */
    public function badge(): ?string
    {
        return match (true) {
            $this->gone => rex_i18n::rawMsg('ai_platform_change_state_gone'),
            $this->hasBrokenReferences() => rex_i18n::rawMsg('ai_platform_change_state_broken'),
            $this->changed => rex_i18n::rawMsg('ai_platform_change_state_changed'),
            $this->contextChanged => rex_i18n::rawMsg('ai_platform_change_state_context'),
            default => null,
        };
    }

    public function badgeCssClass(): string
    {
        return match (true) {
            $this->gone, $this->hasBrokenReferences() => 'label-danger',
            $this->changed => 'label-warning',
            $this->contextChanged => 'label-info',
            default => 'label-success',
        };
    }

    /**
     * Everything worth telling the reviewer, as a flat list.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = [];

        if ($this->gone) {
            $messages[] = rex_i18n::rawMsg('ai_platform_change_target_gone_warning');
        }

        if ($this->changed) {
            $messages[] = [] === $this->changedFields
                ? rex_i18n::rawMsg('ai_platform_change_changed_generic')
                : rex_i18n::rawMsg('ai_platform_change_changed_fields', implode(', ', $this->changedFields));
        }

        foreach ($this->brokenReferences as $reference) {
            $messages[] = $reference;
        }

        foreach ($this->contextChanges as $change) {
            $messages[] = $change;
        }

        return $messages;
    }
}
