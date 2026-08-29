<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use FriendsOfRedaxo\AiPlatform\Change\Diff\DiffField;
use rex_i18n;
use RuntimeException;

use function array_key_exists;
use function is_bool;
use function is_scalar;

/**
 * Sensible defaults for handlers: fingerprinting, a generic field diff, and
 * "revert not supported".
 *
 * Subclasses override what they can do better — a handler that knows its
 * field labels produces a far more readable diff than the generic one, and
 * diff quality is a security property here, not decoration: the approval gate
 * only protects against a manipulated proposal if the reviewer can actually
 * see what it does.
 */
abstract class AbstractHandler implements HandlerInterface
{
    /**
     * Note on translations throughout lib/Change: rawMsg() is used, never
     * msg(). msg() applies html_simplified escaping, and everything here is
     * consumed by callers that escape themselves — the backend pages, or an
     * agent reading an error string. Using msg() would show `&quot;` in both.
     */
    public function getLabel(): string
    {
        return rex_i18n::rawMsg('ai_platform_change_type_' . $this->getType());
    }

    public function supportedOperations(): array
    {
        return [ChangeOperation::Create, ChangeOperation::Update, ChangeOperation::Delete];
    }

    public function supportsRevert(): bool
    {
        return false;
    }

    /**
     * No context by default. Types where the surroundings matter — anything
     * with siblings or ordering — override this.
     */
    public function readContext(TargetInterface $target): ?array
    {
        return null;
    }

    /**
     * No references by default. Types whose payload can point at other objects
     * override this.
     *
     * @return list<string>
     */
    public function checkReferences(ChangeRequest $request): array
    {
        return [];
    }

    /**
     * Turns the reference check into a validation error. Handlers call this
     * from validate() so both paths agree.
     *
     * @throws ValidationException
     */
    final protected function assertReferencesResolve(ChangeRequest $request): void
    {
        $broken = $this->checkReferences($request);
        if ([] !== $broken) {
            throw new ValidationException(implode(' ', $broken));
        }
    }

    /**
     * Fingerprint over a context snapshot. Same normalisation as
     * {@see fingerprint()}, kept separate so a handler can override one without
     * the other.
     *
     * @param array<string, scalar|null>|null $context
     */
    final public function contextFingerprint(?array $context): string
    {
        return $this->fingerprint($context);
    }

    public function revert(ChangeRequest $request): ApplyResult
    {
        throw new RuntimeException(sprintf('Handler "%s" does not support revert.', $this->getType()));
    }

    /**
     * Fingerprint over the target's current values.
     *
     * Keys are sorted and values cast to string, so the hash does not change
     * just because a column came back as int instead of numeric string from a
     * different code path. Null is distinguished from empty string, because
     * "field never set" and "field cleared" are genuinely different states.
     *
     * @param array<string, scalar|null>|null $values
     */
    final public function fingerprint(?array $values): string
    {
        if (null === $values) {
            return 'absent';
        }

        $normalised = [];
        foreach ($values as $key => $value) {
            $normalised[(string) $key] = match (true) {
                null === $value => "\0null",
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            };
        }
        ksort($normalised);

        return hash('sha256', json_encode($normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Generic diff: one row per field the payload touches.
     *
     * Only touched fields appear. That is what makes an unmapped slice diff
     * usable — a proposal that changes one slot shows one row, and the old
     * value next to the new one identifies the field well enough to decide,
     * even when its name is just "value7".
     *
     * @param array<string, scalar|null>|null $currentValues
     * @return list<DiffField>
     */
    public function diffFields(ChangeRequest $request, ?array $currentValues = null): array
    {
        $payload = $request->effectivePayload()->toArray();
        $before = $request->snapshotBefore ?? [];

        $fields = [];
        foreach ($payload as $key => $after) {
            $fields[] = DiffField::compare(
                (string) $key,
                $this->fieldLabel((string) $key, $request),
                array_key_exists($key, $before) ? self::stringify($before[$key]) : null,
                self::stringify($after),
                null === $currentValues ? null : (array_key_exists($key, $currentValues) ? self::stringify($currentValues[$key]) : null),
            );
        }

        return $fields;
    }

    /**
     * Human label for a payload key. Subclasses that know their schema —
     * metainfo titles, YForm labels — should override this.
     */
    protected function fieldLabel(string $key, ChangeRequest $request): string
    {
        return $key;
    }

    /**
     * Normalises a stored value for display and comparison.
     */
    final protected static function stringify(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Guards against a payload key that is not a legitimate column for this
     * type. Never merge a raw payload into an SQL update — a payload comes
     * from outside, and `id` or `createuser` must not be reachable.
     *
     * @param array<string, scalar|null> $payload
     * @param list<string> $allowed
     * @return array<string, scalar|null>
     * @throws ValidationException
     */
    final protected static function filterToAllowed(array $payload, array $allowed): array
    {
        $unknown = array_diff(array_keys($payload), $allowed);
        if ([] !== $unknown) {
            throw new ValidationException(sprintf(
                'Not writable: %s. Allowed: %s',
                implode(', ', $unknown),
                implode(', ', $allowed),
            ));
        }

        return $payload;
    }
}
