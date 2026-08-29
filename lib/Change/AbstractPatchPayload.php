<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use InvalidArgumentException;

use function array_key_exists;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Base for all payloads. Holds only the fields that were explicitly set.
 *
 * Subclasses expose typed setters and funnel them through {@see put()},
 * which is the single place that decides what a storable value looks like.
 * Nothing else may write to $fields — that guarantee is what makes the
 * payload trustworthy once it comes back out of the database.
 */
abstract class AbstractPatchPayload implements PayloadInterface
{
    /** @var array<string, scalar|null> */
    private array $fields = [];

    /**
     * Sets one storable field. Values are normalised to scalars because the
     * payload is persisted as JSON and written into SQL columns.
     */
    final protected function put(string $key, string|int|float|bool|null $value): static
    {
        if ('' === $key) {
            throw new InvalidArgumentException('Payload field name must not be empty.');
        }

        $clone = clone $this;
        $clone->fields[$key] = $value;

        return $clone;
    }

    final public function has(string $key): bool
    {
        return array_key_exists($key, $this->fields);
    }

    final public function get(string $key): string|int|float|bool|null
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    final public function keys(): array
    {
        return array_keys($this->fields);
    }

    final public function isEmpty(): bool
    {
        return [] === $this->fields;
    }

    /**
     * Nothing is attached by default. Override where a payload can carry a file.
     *
     * @return list<string>
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * @return array<string, scalar|null>
     */
    final public function toArray(): array
    {
        return $this->fields;
    }

    /**
     * Rehydrates a payload from its persisted form.
     *
     * Deliberately bypasses the typed setters: the values already passed
     * them when the request was proposed, and a later schema change (a
     * metainfo field removed, a YForm column dropped) must not make an
     * existing request unloadable — the reviewer still needs to see it, and
     * validate() will reject it at apply time with a proper message.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        /** @psalm-suppress UnsafeInstantiation */
        $payload = new static();

        foreach ($data as $key => $value) {
            if (!is_string($key) || '' === $key) {
                continue;
            }
            if (null !== $value && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                continue;
            }
            $payload->fields[$key] = $value;
        }

        return $payload;
    }

    /**
     * Guard for the numbered REX_VALUE style slots.
     */
    final protected static function assertSlot(int $slot, int $max, string $what): void
    {
        if ($slot < 1 || $slot > $max) {
            throw new InvalidArgumentException(sprintf(
                '%s slot must be between 1 and %d, got %d.',
                $what,
                $max,
                $slot,
            ));
        }
    }
}
