<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Payload;

use FriendsOfRedaxo\AiPlatform\Change\AbstractPatchPayload;
use FriendsOfRedaxo\AiPlatform\Change\Support\YformFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Target\YformTarget;
use InvalidArgumentException;
use rex_yform_manager_field;

use function is_array;
use function is_int;
use function is_string;

/**
 * Field values for a YForm dataset.
 *
 * Bound to a table, because that is the only way to check a field name
 * against the real schema. YForm exposes its fields fully — name, label,
 * type, column type — so this payload can tell a single-value field from a
 * list field and refuse the wrong shape at set time.
 *
 * On apply, YForm's own validation runs as well (dataset->isValid()), so a
 * proposal is checked against exactly the same rules a human form
 * submission would face.
 */
final class YformPayload extends AbstractPatchPayload
{
    private ?string $table = null;

    public static function forTable(string $table): self
    {
        $payload = new self();
        $payload->table = $table;

        return $payload;
    }

    public static function forTarget(YformTarget $target): self
    {
        return self::forTable($target->getTable());
    }

    /**
     * A single value.
     */
    public function set(string $field, string|int|float|bool|null $value): self
    {
        $definition = $this->requireField($field);

        if (null !== $definition && YformFieldRegistry::acceptsList($definition) && null !== $value) {
            // Not an error: a relation or choice field can legitimately hold
            // exactly one value. Kept explicit so the intent is visible.
            return $this->put($field, (string) $value);
        }

        return $this->put($field, $value);
    }

    /**
     * Several values in one field.
     *
     * @param list<string|int> $values
     */
    public function setList(string $field, array $values): self
    {
        $definition = $this->requireField($field);

        if (null !== $definition && !YformFieldRegistry::acceptsList($definition)) {
            throw new InvalidArgumentException(sprintf(
                'YForm field "%s" (%s) does not hold multiple values — use set().',
                $field,
                (string) $definition->getTypeName(),
            ));
        }

        $normalised = [];
        foreach ($values as $value) {
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException('YForm list values must be strings or integers.');
            }
            $value = trim((string) $value);
            if ('' === $value) {
                continue;
            }
            if (str_contains($value, ',')) {
                throw new InvalidArgumentException(sprintf(
                    'YForm value "%s" must not contain a comma — multi-value fields are stored comma separated.',
                    $value,
                ));
            }
            $normalised[] = $value;
        }

        return $this->put(
            $field,
            null === $definition ? implode(',', $normalised) : YformFieldRegistry::encodeList($definition, $normalised),
        );
    }

    /**
     * A be_manager_relation field: one id or a list of ids.
     *
     * @param int|list<int> $ids
     */
    public function setRelation(string $field, int|array $ids): self
    {
        $definition = $this->requireField($field);

        if (null !== $definition && 'be_manager_relation' !== (string) $definition->getTypeName()) {
            throw new InvalidArgumentException(sprintf(
                'YForm field "%s" is a %s, not a relation — use set() or setList().',
                $field,
                (string) $definition->getTypeName(),
            ));
        }

        $list = is_array($ids) ? $ids : [$ids];
        foreach ($list as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Relation ids must be positive integers.');
            }
        }

        return $this->put($field, implode(',', array_map('strval', $list)));
    }

    /**
     * Resolves and validates a field name.
     *
     * Returns null when YForm cannot be asked (addon missing, table unknown
     * at build time). In that case the name is accepted and checked again on
     * apply — refusing here would make the payload unbuildable in a CLI or
     * test context where the table exists but YForm is not booted.
     */
    private function requireField(string $field): ?rex_yform_manager_field
    {
        if (null === $this->table) {
            throw new InvalidArgumentException(
                'YformPayload needs a table: use YformPayload::forTable($table).',
            );
        }

        if ('' === trim($field)) {
            throw new InvalidArgumentException('YForm field name must not be empty.');
        }

        if ('id' === $field) {
            throw new InvalidArgumentException('The id column cannot be written through a change request.');
        }

        $fields = YformFieldRegistry::forTable($this->table);
        if ([] === $fields) {
            return null;
        }

        $definition = $fields[$field] ?? null;
        if (null === $definition) {
            throw new InvalidArgumentException(sprintf(
                'Unknown YForm field "%s" in table "%s". Known fields: %s',
                $field,
                $this->table,
                implode(', ', array_keys(YformFieldRegistry::editableFields($this->table))),
            ));
        }

        // Refused rather than silently dropped: YForm writes datestamp fields on
        // every save and showvalue fields have no input, so a proposal setting
        // one would look accepted and then do nothing.
        if (YformFieldRegistry::isAutomatic($definition)) {
            throw new InvalidArgumentException(sprintf(
                'YForm field "%s" is a %s field, which YForm maintains itself — it cannot be set through a change request.',
                $field,
                (string) $definition->getTypeName(),
            ));
        }

        return $definition;
    }

    public function getTable(): ?string
    {
        return $this->table;
    }
}
