<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

/**
 * A snapshot of a target's current values, plus the fingerprint over them.
 *
 * Returned by ChangeService::read() so a caller can see what it is about to
 * change. The fingerprint is the same one stored with a proposal, which is
 * what makes stale detection work at review time.
 */
final class CurrentState
{
    /**
     * @param array<string, scalar|null>|null $values null when the target does not exist
     */
    public function __construct(
        public readonly ?array $values,
        public readonly string $fingerprint,
        public readonly string $description,
    ) {
    }

    public function exists(): bool
    {
        return null !== $this->values;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'values' => $this->values,
            'fingerprint' => $this->fingerprint,
            'description' => $this->description,
        ];
    }
}
