<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Support;

use rex_sql;
use rex_sql_exception;
use rex_yform_manager_field;
use rex_yform_manager_table;

use function in_array;
use function is_string;

/**
 * Reads YForm field metadata for a table.
 *
 * YForm is the one change type with fully machine-readable field
 * information: getValueFields() yields name, label, type and column type
 * per field. That is what makes the payload validate properly instead of
 * hoping the caller knows the schema.
 */
final class YformFieldRegistry
{
    /**
     * Field types that hold several values in one column. The exact
     * serialisation is asked of the field itself where possible; these are
     * the types where a list is accepted at all.
     */
    private const LIST_TYPES = [
        'checkbox',
        'choice',
        'be_manager_relation',
    ];

    /** @var array<string, array<string, rex_yform_manager_field>> */
    private static array $cache = [];

    public static function isAvailable(): bool
    {
        return class_exists(rex_yform_manager_table::class);
    }

    /**
     * Value fields of a table, keyed by field name. Empty when YForm is not
     * installed or the table is unknown.
     *
     * @return array<string, rex_yform_manager_field>
     */
    public static function forTable(string $table): array
    {
        if (isset(self::$cache[$table])) {
            return self::$cache[$table];
        }

        if (!self::isAvailable()) {
            return self::$cache[$table] = [];
        }

        $yformTable = rex_yform_manager_table::get($table);
        if (null === $yformTable) {
            return self::$cache[$table] = [];
        }

        $columns = self::columnsOf($table);

        $fields = [];
        foreach ($yformTable->getValueFields() as $field) {
            $name = (string) $field->getName();
            if ('' === $name || !self::isStored($field) || !isset($columns[$name])) {
                continue;
            }
            $fields[$name] = $field;
        }

        return self::$cache[$table] = $fields;
    }

    /**
     * Column names of a table, as a lookup.
     *
     * The declared type is not enough to tell whether a field has a column.
     * An n:m be_manager_relation declares `db_type => text` but stores its
     * values in a join table, so the main table has no such column at all —
     * reading it raises "undefined array key" and writing it would go nowhere.
     * Comparing against the real columns is the only reliable test, and it also
     * keeps such relations out of change requests, which is the honest outcome:
     * changing one needs handling of its own, not a value assignment.
     *
     * @return array<string, true>
     */
    private static function columnsOf(string $table): array
    {
        try {
            $rows = rex_sql::factory()->getArray('SHOW COLUMNS FROM ' . rex_sql::factory()->escapeIdentifier($table));
        } catch (rex_sql_exception) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $name = $row['Field'] ?? null;
            if (is_string($name) && '' !== $name) {
                $columns[$name] = true;
            }
        }

        return $columns;
    }

    /**
     * Whether the field declares storage at all.
     *
     * getValueFields() also returns presentation-only fields — `html`,
     * `fieldset`, anything flagged no_db — which declare `db_type => none`.
     */
    private static function isStored(rex_yform_manager_field $field): bool
    {
        if ('none' === (string) $field->getDatabaseFieldType()) {
            return false;
        }

        return '1' !== (string) $field->getElement('no_db') && 1 !== $field->getElement('no_db');
    }

    /**
     * Field types YForm fills in itself, or that only display something.
     *
     * `datestamp` is written on every save regardless of input, and `showvalue`
     * has no input at all. Proposing a value for either is meaningless — YForm
     * would overwrite it or ignore it — so they are refused rather than silently
     * dropped, and they are not offered in the form.
     *
     * They stay in {@see forTable()} though: they have columns, and a snapshot
     * that skipped them would miss changes to them.
     */
    private const AUTOMATIC_TYPES = [
        'datestamp',
        'showvalue',
    ];

    /**
     * Fields a proposal may actually set.
     *
     * @return array<string, rex_yform_manager_field>
     */
    public static function editableFields(string $table): array
    {
        return array_filter(
            self::forTable($table),
            static fn(rex_yform_manager_field $field): bool => !self::isAutomatic($field),
        );
    }

    public static function isAutomatic(rex_yform_manager_field $field): bool
    {
        return in_array((string) $field->getTypeName(), self::AUTOMATIC_TYPES, true);
    }

    public static function field(string $table, string $name): ?rex_yform_manager_field
    {
        return self::forTable($table)[$name] ?? null;
    }

    /**
     * Whether this field can take a list of values.
     */
    public static function acceptsList(rex_yform_manager_field $field): bool
    {
        return in_array((string) $field->getTypeName(), self::LIST_TYPES, true);
    }

    /**
     * Serialises a list for a given field.
     *
     * YForm stores multi-value fields comma separated in a single column.
     * Values containing a comma cannot be represented and are rejected by
     * the payload rather than silently split on read.
     *
     * @param list<string> $values
     */
    public static function encodeList(rex_yform_manager_field $field, array $values): string
    {
        return implode(',', $values);
    }

    /**
     * @return list<string>
     */
    public static function decodeList(?string $stored): array
    {
        if (null === $stored || '' === $stored) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $stored)), static fn(string $v): bool => '' !== $v));
    }

    /**
     * Human label for a field, falling back to its name.
     */
    public static function label(rex_yform_manager_field $field): string
    {
        $label = (string) $field->getLabel();

        return '' !== $label ? $label : (string) $field->getName();
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
