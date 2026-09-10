<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Support;

use rex;
use rex_metainfo_default_type;
use rex_sql;
use rex_sql_exception;
use rex_string;

use function in_array;
use function is_scalar;

/**
 * Reads metainfo field definitions so payloads can validate against what
 * actually exists, instead of trusting a caller's field names.
 *
 * This is the whitelist for the `meta` change type: anything not returned
 * here is not writable. The api addon does the same check inline in its
 * metainfo routes; here it is shared between the payload (build time), the
 * handler (apply time) and the backend form (field rendering).
 */
final class MetaFieldRegistry
{
    /**
     * Multi-value types. REDAXO stores these pipe-wrapped (`|a|b|`) — see
     * rex_metainfo_handler, which builds '|' . implode('|', $value) . '|'.
     * SELECT only counts when its attributes carry `multiple`.
     */
    private const MULTI_TYPES = [
        rex_metainfo_default_type::CHECKBOX,
        rex_metainfo_default_type::SELECT,
    ];

    /** @var array<string, array<string, array{id: int, name: string, type_id: int, attributes: string, title: string}>> */
    private static array $cache = [];

    /**
     * All value fields for a prefix (`art_`, `cat_`, `med_`, `clang_`),
     * keyed by field name. LEGEND fields are excluded: they are visual
     * separators in the backend form and have no column to write to.
     *
     * @return array<string, array{id: int, name: string, type_id: int, attributes: string, title: string}>
     */
    public static function forPrefix(string $prefix): array
    {
        if (isset(self::$cache[$prefix])) {
            return self::$cache[$prefix];
        }

        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT id, name, type_id, attributes, title FROM ' . rex::getTable('metainfo_field') . ' WHERE name LIKE :prefix ORDER BY priority',
                [':prefix' => $prefix . '%'],
            );
        } catch (rex_sql_exception) {
            // metainfo not installed — no fields, no writes.
            return self::$cache[$prefix] = [];
        }

        $fields = [];
        foreach ($rows as $row) {
            $typeId = (int) $row['type_id'];
            if (rex_metainfo_default_type::LEGEND === $typeId) {
                continue;
            }
            $name = (string) $row['name'];
            $fields[$name] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'type_id' => $typeId,
                'attributes' => is_scalar($row['attributes'] ?? null) ? (string) $row['attributes'] : '',
                'title' => is_scalar($row['title'] ?? null) ? (string) $row['title'] : $name,
            ];
        }

        return self::$cache[$prefix] = $fields;
    }

    /**
     * @return array{id: int, name: string, type_id: int, attributes: string, title: string}|null
     */
    public static function field(string $prefix, string $name): ?array
    {
        return self::forPrefix($prefix)[$name] ?? null;
    }

    /**
     * Whether this field stores several values at once, and therefore uses
     * the pipe-wrapped format.
     *
     * @param array{type_id: int, attributes: string} $field
     */
    public static function isMultiValue(array $field): bool
    {
        if (!in_array($field['type_id'], self::MULTI_TYPES, true)) {
            return false;
        }

        if (rex_metainfo_default_type::CHECKBOX === $field['type_id']) {
            return true;
        }

        // SELECT is multi-value only when declared as such.
        return isset(rex_string::split($field['attributes'])['multiple']);
    }

    /**
     * Encodes a list of values the way the metainfo addon does.
     *
     * @param list<string> $values
     */
    public static function encodeMultiValue(array $values): string
    {
        if ([] === $values) {
            return '';
        }

        return '|' . implode('|', $values) . '|';
    }

    /**
     * Decodes the pipe format back into a list, for diffing.
     *
     * @return list<string>
     */
    public static function decodeMultiValue(?string $stored): array
    {
        if (null === $stored || '' === $stored) {
            return [];
        }

        return array_values(array_filter(explode('|', $stored), static fn(string $v): bool => '' !== $v));
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
