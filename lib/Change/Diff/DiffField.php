<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Diff;

/**
 * One row of a change request's diff.
 *
 * Handlers produce these; the renderer only formats them. `current` is
 * filled in when the target has moved since the proposal was made, which
 * turns the two-column view into the three-column base / proposed / current
 * comparison a reviewer needs to decide about an override.
 */
final class DiffField
{
    public const KIND_UNCHANGED = 'unchanged';
    public const KIND_CHANGED = 'changed';
    public const KIND_ADDED = 'added';
    public const KIND_REMOVED = 'removed';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $before,
        public readonly ?string $after,
        public readonly string $kind = self::KIND_CHANGED,
        public readonly ?string $current = null,
        public readonly bool $longText = false,
        public readonly ?string $note = null,
    ) {
    }

    /**
     * Builds a row and works out the kind from the values, so handlers do
     * not each reimplement that comparison.
     */
    public static function compare(
        string $key,
        string $label,
        ?string $before,
        ?string $after,
        ?string $current = null,
        ?string $note = null,
    ): self {
        $normalisedBefore = null === $before ? null : trim($before);
        $normalisedAfter = null === $after ? null : trim($after);

        $kind = match (true) {
            $normalisedBefore === $normalisedAfter => self::KIND_UNCHANGED,
            null === $normalisedBefore || '' === $normalisedBefore => self::KIND_ADDED,
            null === $normalisedAfter || '' === $normalisedAfter => self::KIND_REMOVED,
            default => self::KIND_CHANGED,
        };

        return new self(
            $key,
            $label,
            $before,
            $after,
            $kind,
            $current,
            self::looksLikeLongText($before) || self::looksLikeLongText($after),
            $note,
        );
    }

    /**
     * Whether the value warrants a line-based diff rather than a
     * side-by-side cell. Anything with newlines, or longer than a headline.
     */
    private static function looksLikeLongText(?string $value): bool
    {
        if (null === $value) {
            return false;
        }

        return str_contains($value, "\n") || mb_strlen($value) > 120;
    }

    public function isChanged(): bool
    {
        return self::KIND_UNCHANGED !== $this->kind;
    }

    /**
     * True when someone edited the target after the proposal was made and
     * the current value differs from what the proposal was based on.
     */
    public function isConflicting(): bool
    {
        if (null === $this->current) {
            return false;
        }

        return trim($this->current) !== trim((string) $this->before);
    }

    public function cssClass(): string
    {
        return match ($this->kind) {
            self::KIND_ADDED => 'ai-diff-added',
            self::KIND_REMOVED => 'ai-diff-removed',
            self::KIND_CHANGED => 'ai-diff-changed',
            default => 'ai-diff-unchanged',
        };
    }
}
