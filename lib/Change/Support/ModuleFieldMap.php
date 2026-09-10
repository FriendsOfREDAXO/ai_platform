<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Support;

use rex;
use rex_extension;
use rex_extension_point;
use rex_sql;

use function is_array;
use function is_int;
use function is_string;

/**
 * Optional, project-supplied names for a module's REX_VALUE slots.
 *
 * REDAXO modules are input/output PHP — the meaning of value1..value20 is
 * nowhere machine-readable. Without a map, a change request can only talk
 * about slots, and a reviewer reads "value7" in the diff. That is workable
 * (only changed slots are shown, and the old value names the field
 * implicitly) but a map makes both the payload and the diff explicit.
 *
 * A project fills it via the AI_PLATFORM_MODULE_FIELDS extension point:
 *
 *   rex_extension::register('AI_PLATFORM_MODULE_FIELDS', function ($ep) {
 *       $map = $ep->getSubject();
 *       $map[12] = [
 *           'headline' => ['slot' => 'value1', 'label' => 'Überschrift'],
 *           'image'    => ['slot' => 'media1', 'label' => 'Bild'],
 *       ];
 *       return $map;
 *   });
 *
 * Nothing ships pre-filled; every module without a map keeps working on raw
 * slots.
 */
final class ModuleFieldMap
{
    /** @var array<int, array<string, array{slot: string, label: string}>>|null */
    private static ?array $cache = null;

    /**
     * @return array<int, array<string, array{slot: string, label: string}>>
     */
    public static function all(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        /** @var array<mixed> $raw */
        $raw = rex_extension::registerPoint(new rex_extension_point('AI_PLATFORM_MODULE_FIELDS', []));

        $map = [];
        foreach ($raw as $moduleId => $fields) {
            if (!is_int($moduleId) || !is_array($fields)) {
                continue;
            }
            foreach ($fields as $name => $definition) {
                if (!is_string($name) || '' === $name || !is_array($definition)) {
                    continue;
                }
                $slot = $definition['slot'] ?? null;
                if (!is_string($slot) || 1 !== preg_match('/^(value([1-9]|1\d|20)|(media|medialist|link|linklist)([1-9]|10))$/', $slot)) {
                    continue;
                }
                $label = $definition['label'] ?? $name;
                $map[$moduleId][$name] = [
                    'slot' => $slot,
                    'label' => is_string($label) && '' !== $label ? $label : $name,
                ];
            }
        }

        return self::$cache = $map;
    }

    /**
     * @return array<string, array{slot: string, label: string}>
     */
    public static function forModule(int $moduleId): array
    {
        return self::all()[$moduleId] ?? [];
    }

    public static function hasMap(int $moduleId): bool
    {
        return [] !== self::forModule($moduleId);
    }

    /**
     * Resolves a field name to its storage slot, or null when the module has
     * no map or does not know the name.
     */
    public static function resolveSlot(int $moduleId, string $field): ?string
    {
        return self::forModule($moduleId)[$field]['slot'] ?? null;
    }

    /**
     * Reverse lookup for the diff: the human label for a storage slot, or
     * null when unmapped — callers then fall back to the raw slot name.
     */
    public static function labelForSlot(int $moduleId, string $slot): ?string
    {
        foreach (self::forModule($moduleId) as $definition) {
            if ($definition['slot'] === $slot) {
                return $definition['label'];
            }
        }

        return null;
    }

    /**
     * Test seam — the extension point result is cached per request.
     */
    /**
     * Which slots a module actually uses, read from its own input and output.
     *
     * This is the answer to the question a caller cannot otherwise get: the
     * catalogue says value1..value20 exist, but a given module usually reads
     * two of them. Without this, building a slice means writing text into
     * value1 and hoping — which is exactly what happened when an agent was
     * given nothing but the endpoint descriptions.
     *
     * Read from the module rather than declared, for the same reason
     * ChangeService::moduleExecutesPhp() reads the output: a declaration has to
     * be maintained and the one entry that matters is the one nobody updated.
     * A registered AI_PLATFORM_MODULE_FIELDS entry still wins for the label,
     * because a project knows better than a regex what a field is called.
     *
     * @return array<string, array{type: string, label: string|null}> slot => info, in slot order
     */
    public static function detectSlots(int $moduleId): array
    {
        if ($moduleId < 1) {
            return [];
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT input, output FROM ' . rex::getTable('module') . ' WHERE id = :id',
            [':id' => $moduleId],
        );
        if ([] === $rows) {
            return [];
        }

        $code = (string) ($rows[0]['input'] ?? '') . "\n" . (string) ($rows[0]['output'] ?? '');

        // REX_INPUT_VALUE[1], REX_VALUE[1], REX_VALUE[id=1 output=html],
        // REX_MEDIA[id=1 widget=1], REX_MEDIALIST[…], REX_LINK[…],
        // REX_LINKLIST[…] — the id may or may not be named.
        $found = [];
        if (0 !== preg_match_all(
            '/REX_(?:INPUT_)?(VALUE|MEDIALIST|MEDIA|LINKLIST|LINK)\[\s*(?:id\s*=\s*)?(\d+)/i',
            $code,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $match) {
                $kind = strtolower($match[1]);
                $index = (int) $match[2];
                if ($index < 1) {
                    continue;
                }
                $found[$kind . $index] = $index;
            }
        }

        if ([] === $found) {
            return [];
        }

        $named = self::forModule($moduleId);
        $labelBySlot = [];
        foreach ($named as $name => $definition) {
            $labelBySlot[$definition['slot']] = '' !== $definition['label'] ? $definition['label'] : $name;
        }

        // Sort by kind then index, so value1 comes before value2 and values
        // before media — a stable order the caller can rely on.
        uksort($found, static function (string $a, string $b) use ($found): int {
            $kindA = rtrim($a, '0123456789');
            $kindB = rtrim($b, '0123456789');

            return [$kindA, $found[$a]] <=> [$kindB, $found[$b]];
        });

        $slots = [];
        foreach ($found as $slot => $index) {
            $slots[$slot] = [
                'type' => match (true) {
                    str_starts_with($slot, 'medialist') => 'media filenames, comma separated',
                    str_starts_with($slot, 'media') => 'media filename',
                    str_starts_with($slot, 'linklist') => 'article ids, comma separated',
                    str_starts_with($slot, 'link') => 'article id',
                    default => 'text',
                },
                'label' => $labelBySlot[$slot] ?? null,
            ];
        }

        return $slots;
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
