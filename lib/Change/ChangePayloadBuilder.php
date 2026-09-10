<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use FriendsOfRedaxo\AiPlatform\Change\Payload\ArticlePayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\CategoryPayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\MediaPayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\MetaPayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\SlicePayload;
use FriendsOfRedaxo\AiPlatform\Change\Payload\YformPayload;
use FriendsOfRedaxo\AiPlatform\Change\Support\MetaFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Support\ModuleFieldMap;
use FriendsOfRedaxo\AiPlatform\Change\Support\YformFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Target\ArticleTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\CategoryTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\MediaTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\MetaTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\SliceTarget;
use FriendsOfRedaxo\AiPlatform\Change\Target\YformTarget;
use InvalidArgumentException;
use rex;
use rex_clang;
use rex_sql;

use function is_array;
use function is_scalar;

/**
 * Turns loose arrays into typed targets and payloads.
 *
 * Used by the agent tool and by the backend form, which both receive name/value
 * maps rather than objects. Everything is routed through the typed setters, so
 * a caller coming in through here faces exactly the same guards as one written
 * in PHP — slot ranges, prefix checks, list formats, unknown field names.
 *
 * This is the only place that maps loose input to objects. Duplicating it per
 * entry point is how the two would drift apart.
 */
final class ChangePayloadBuilder
{
    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $fields
     * @return array{target: TargetInterface, payload: PayloadInterface}
     */
    public static function build(
        HandlerInterface $handler,
        ChangeOperation $operation,
        array $target,
        array $fields,
    ): array {
        $fields = self::normaliseFields($fields);

        return match ($handler->getType()) {
            'slice' => self::buildSlice($operation, $target, $fields),
            'article' => self::buildArticle($operation, $target, $fields),
            'category' => self::buildCategory($operation, $target, $fields),
            'meta' => self::buildMeta($target, $fields),
            'media' => self::buildMedia($operation, $target, $fields),
            'yform' => self::buildYform($operation, $target, $fields),
            default => throw new InvalidArgumentException(sprintf(
                'No array mapping for change type "%s". Build its target and payload in PHP, or extend ChangePayloadBuilder.',
                $handler->getType(),
            )),
        };
    }

    /**
     * Drops entries that carry nothing, so a caller cannot clear a field
     * through this builder.
     *
     * The comment here used to say a single space got through. It does not:
     * the check is `'' === trim($value)`, which discards a space along with an
     * empty string. Verified over HTTP — both answer 422 "must set at least one
     * field". Clearing therefore needs the PHP API (build the payload object
     * directly) or an editor in the backend.
     *
     * The original reason for dropping empties was the backend entry form,
     * which could not tell "left alone" from "emptied". That form is gone; if
     * clearing over REST is wanted, this is the single place to change — but it
     * is a behaviour change, not a bug fix, because a caller sending a stray
     * empty value would then start wiping content.
     *
     * @param array<string, mixed> $fields
     * @return array<string, string>
     */
    private static function normaliseFields(array $fields): array
    {
        $clean = [];
        foreach ($fields as $key => $value) {
            if (!is_scalar($value) && null !== $value) {
                continue;
            }
            $value = null === $value ? '' : (string) $value;
            if ('' === trim($value)) {
                continue;
            }
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, string> $fields
     * @return array{target: TargetInterface, payload: PayloadInterface}
     */
    private static function buildSlice(ChangeOperation $operation, array $target, array $fields): array
    {
        $articleId = self::int($target, 'article_id');
        $clangId = self::int($target, 'clang_id', rex_clang::getStartId());

        $sliceTarget = match ($operation) {
            ChangeOperation::Create => SliceTarget::createIn(
                $articleId,
                $clangId,
                self::int($target, 'module_id'),
                self::int($target, 'ctype_id', 1),
                isset($target['priority']) ? self::int($target, 'priority') : null,
            ),
            ChangeOperation::Update => SliceTarget::existing(self::int($target, 'slice_id'), $articleId),
            ChangeOperation::Delete => SliceTarget::forDeletion(self::int($target, 'slice_id'), $articleId),
        };

        $payload = new SlicePayload();
        foreach ($fields as $slot => $value) {
            // Every slot goes through its typed setter, so ranges and list
            // formats are enforced even for input arriving as a flat map.
            if (1 === preg_match('/^value(\d+)$/', $slot, $m)) {
                $payload = $payload->value((int) $m[1], $value);
            } elseif (1 === preg_match('/^media(\d+)$/', $slot, $m)) {
                $payload = $payload->media((int) $m[1], $value);
            } elseif (1 === preg_match('/^medialist(\d+)$/', $slot, $m)) {
                $payload = $payload->mediaList((int) $m[1], self::splitList($value));
            } elseif (1 === preg_match('/^link(\d+)$/', $slot, $m)) {
                $payload = $payload->link((int) $m[1], (int) $value);
            } elseif (1 === preg_match('/^linklist(\d+)$/', $slot, $m)) {
                $payload = $payload->linkList((int) $m[1], array_map('intval', self::splitList($value)));
            } else {
                // Not a slot — try it as a mapped module field name, which
                // throws a helpful message when no mapping exists.
                $moduleId = $sliceTarget->getModuleId();
                if (null === $moduleId) {
                    throw new InvalidArgumentException(sprintf('"%s" is not a slice slot.', $slot));
                }
                $payload = SlicePayload::forModule($moduleId)->field($slot, $value);
            }
        }

        return ['target' => $sliceTarget, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, string> $fields
     * @return array{target: TargetInterface, payload: PayloadInterface}
     */
    private static function buildArticle(ChangeOperation $operation, array $target, array $fields): array
    {
        $clangId = self::int($target, 'clang_id', rex_clang::getStartId());

        $articleTarget = match ($operation) {
            ChangeOperation::Create => ArticleTarget::createIn(self::int($target, 'category_id', 0), $clangId),
            ChangeOperation::Update => ArticleTarget::existing(self::int($target, 'article_id'), $clangId),
            ChangeOperation::Delete => ArticleTarget::forDeletion(self::int($target, 'article_id'), $clangId),
        };

        $payload = new ArticlePayload();
        foreach ($fields as $name => $value) {
            $payload = match ($name) {
                'name' => $payload->name($value),
                'priority' => $payload->priority((int) $value),
                'template_id' => $payload->templateId((int) $value),
                'status' => $payload->status((int) $value),
                default => throw new InvalidArgumentException(sprintf(
                    '"%s" is not an article field. Available: %s',
                    $name,
                    implode(', ', ArticlePayload::allFields()),
                )),
            };
        }

        return ['target' => $articleTarget, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, string> $fields
     * @return array{target: TargetInterface, payload: PayloadInterface}
     */
    private static function buildCategory(ChangeOperation $operation, array $target, array $fields): array
    {
        $clangId = self::int($target, 'clang_id', rex_clang::getStartId());

        $categoryTarget = match ($operation) {
            ChangeOperation::Create => CategoryTarget::createIn(self::int($target, 'parent_id', 0), $clangId),
            ChangeOperation::Update => CategoryTarget::existing(self::int($target, 'category_id'), $clangId),
            ChangeOperation::Delete => CategoryTarget::forDeletion(self::int($target, 'category_id'), $clangId),
        };

        $payload = new CategoryPayload();
        foreach ($fields as $name => $value) {
            $payload = match ($name) {
                // Accept both the friendly and the column name — an agent
                // reading a diff sees "catname" and will send it back.
                'name', 'catname' => $payload->name($value),
                'priority', 'catpriority' => $payload->priority((int) $value),
                'status' => $payload->status((int) $value),
                default => throw new InvalidArgumentException(sprintf(
                    '"%s" is not a category field. Available: %s',
                    $name,
                    implode(', ', CategoryPayload::allFields()),
                )),
            };
        }

        return ['target' => $categoryTarget, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, string> $fields
     * @return array{target: TargetInterface, payload: PayloadInterface}
     */
    private static function buildMeta(array $target, array $fields): array
    {
        // No silent default. This used to fall back to "article", which turned a
        // media proposal that forgot the carrier into the error message
        // 'Target field "article_id" is required.' — pointing at a field the
        // caller was right not to send, and inviting it to invent an article id
        // and hit the wrong carrier. A guess that produces a misleading error is
        // worse than no guess.
        if (!isset($target['carrier']) || !is_scalar($target['carrier']) || '' === (string) $target['carrier']) {
            throw new ValidationException(
                'A metainfo target needs "carrier". Use article, category, media or clang.'
                . self::guessCarrierHint(array_keys($fields)),
            );
        }

        $carrier = (string) $target['carrier'];
        $clangId = self::int($target, 'clang_id', rex_clang::getStartId());

        $metaTarget = match ($carrier) {
            MetaTarget::CARRIER_CATEGORY => MetaTarget::category(self::int($target, 'category_id'), $clangId),
            MetaTarget::CARRIER_MEDIA => MetaTarget::media(self::string($target, 'filename')),
            MetaTarget::CARRIER_CLANG => MetaTarget::clang($clangId),
            MetaTarget::CARRIER_ARTICLE => MetaTarget::article(self::int($target, 'article_id'), $clangId),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown metainfo carrier "%s". Use article, category, media or clang.',
                $carrier,
            )),
        };

        $payload = MetaPayload::forTarget($metaTarget);
        $prefix = $metaTarget->getFieldPrefix();

        foreach ($fields as $name => $value) {
            $definition = MetaFieldRegistry::field($prefix, $name);
            if (null === $definition) {
                // Let the payload produce the error — it knows the field list.
                $payload = $payload->set($name, $value);
                continue;
            }
            $payload = MetaFieldRegistry::isMultiValue($definition)
                ? $payload->setList($name, self::splitList($value))
                : $payload->set($name, $value);
        }

        return ['target' => $metaTarget, 'payload' => $payload];
    }

    /**
     * Names the carrier the given field names imply, as a hint in the error.
     *
     * Deliberately a hint and not a fallback: reading the intent off the field
     * prefix is a guess, and a guess that silently succeeds is how a value ends
     * up on the wrong carrier. Saying "your fields look like media" costs the
     * caller one corrected request and costs nobody a wrong write.
     *
     * @param list<array-key> $fieldNames
     */
    private static function guessCarrierHint(array $fieldNames): string
    {
        $prefixes = [
            'med_' => MetaTarget::CARRIER_MEDIA,
            'art_' => MetaTarget::CARRIER_ARTICLE,
            'cat_' => MetaTarget::CARRIER_CATEGORY,
            'clang_' => MetaTarget::CARRIER_CLANG,
        ];

        $found = [];
        foreach ($fieldNames as $name) {
            foreach ($prefixes as $prefix => $carrier) {
                if (str_starts_with((string) $name, $prefix)) {
                    $found[$carrier] = true;
                }
            }
        }

        // Only when the fields point at exactly one carrier. Mixed prefixes are a
        // different mistake and would be a misleading hint.
        if (1 !== count($found)) {
            return '';
        }

        return sprintf(' The fields given suggest carrier "%s".', array_key_first($found));
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, string> $fields
     * @return array{target: TargetInterface, payload: PayloadInterface}
     */
    private static function buildMedia(ChangeOperation $operation, array $target, array $fields): array
    {
        // A create needs the category to land in and the name the upload
        // reserved. The filename is not derived from the handle on purpose: the
        // proposal states what it expects, the handler checks the two agree, and
        // a mismatch is an error rather than a silent correction. A diff that
        // promises one name while another is written is worse than a refusal.
        if (ChangeOperation::Create === $operation) {
            $mediaTarget = MediaTarget::createIn(
                self::int($target, 'category_id', 0),
                self::string($target, 'filename'),
            );
        } else {
            $filename = self::string($target, 'filename');
            $mediaTarget = ChangeOperation::Delete === $operation
                ? MediaTarget::forDeletion($filename)
                : MediaTarget::existing($filename);
        }

        $payload = new MediaPayload();
        foreach ($fields as $name => $value) {
            $payload = match ($name) {
                'title' => $payload->title($value),
                'category_id' => $payload->categoryId((int) $value),
                'upload' => $payload->upload((string) $value),
                default => throw new InvalidArgumentException(sprintf(
                    '"%s" is not a media field. Available: %s. Alt text and copyright are metainfo — use type "meta".',
                    $name,
                    implode(', ', MediaPayload::allFields()),
                )),
            };
        }

        return ['target' => $mediaTarget, 'payload' => $payload];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, string> $fields
     * @return array{target: TargetInterface, payload: PayloadInterface}
     */
    private static function buildYform(ChangeOperation $operation, array $target, array $fields): array
    {
        $table = self::string($target, 'table');

        $yformTarget = match ($operation) {
            ChangeOperation::Create => YformTarget::createIn($table),
            ChangeOperation::Update => YformTarget::existing($table, self::int($target, 'dataset_id')),
            ChangeOperation::Delete => YformTarget::forDeletion($table, self::int($target, 'dataset_id')),
        };

        $payload = YformPayload::forTable($table);
        foreach ($fields as $name => $value) {
            $name = self::unqualifyYformField($table, (string) $name);

            $field = YformFieldRegistry::field($table, $name);
            if (null !== $field && YformFieldRegistry::acceptsList($field)) {
                $payload = $payload->setList($name, self::splitList($value));
                continue;
            }
            $payload = $payload->set($name, $value);
        }

        return ['target' => $yformTarget, 'payload' => $payload];
    }

    /**
     * Accepts a YForm field name in either form: `name` or `rex_company.name`.
     *
     * `describe()` has to qualify these names, because one flat list covers every
     * allowed table and `name` exists in several of them. The setters take the
     * bare column. Without this the two disagree, and an agent that does what the
     * documentation tells it — call describe first, then use the names it
     * returned — fails on its first attempt. That happened in testing.
     *
     * A prefix naming a *different* table is an error, not something to strip: it
     * means the caller mixed up which table it is writing to, and silently
     * dropping the prefix would write the value into the wrong column of the
     * right table.
     */
    private static function unqualifyYformField(string $table, string $name): string
    {
        if (!str_contains($name, '.')) {
            return $name;
        }

        [$prefix, $column] = explode('.', $name, 2);
        if ($prefix === $table) {
            return $column;
        }

        throw new ValidationException(sprintf(
            'Field "%s" names table "%s", but the target is "%s". Use the bare column name or '
            . 'prefix it with the target table.',
            $name,
            $prefix,
            $table,
        ));
    }

    /**
     * @return list<string>
     */
    private static function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => '' !== $v));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function int(array $data, string $key, ?int $default = null): int
    {
        $value = $data[$key] ?? null;

        if (null === $value || '' === $value) {
            if (null === $default) {
                throw new InvalidArgumentException(sprintf('Target field "%s" is required.', $key));
            }

            return $default;
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Target field "%s" must be a number.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!is_scalar($value) || '' === trim((string) $value)) {
            throw new InvalidArgumentException(sprintf('Target field "%s" is required.', $key));
        }

        return trim((string) $value);
    }

    /**
     * Field names a caller may use per type, for the describe tool and the
     * backend form. Resolved at call time because metainfo and YForm fields are
     * installation specific.
     *
     * @return array<string, array{target: list<string>, fields: array<string, string>}>
     */
    public static function describe(): array
    {
        $description = [];

        $description['slice'] = [
            'target' => [
                'article_id', 'clang_id', 'slice_id (update/delete)',
                'module_id (create)', 'ctype_id (create)', 'priority (create)',
            ],
            'fields' => self::describeSliceSlots(),
            // The flat slot list above says value1..value20 exist; it cannot say
            // which two a given module reads. Without this a caller writes into
            // value1 and hopes — measured behaviour, not a hypothetical.
            'modules' => self::describeModules(),
        ];

        $description['article'] = [
            'target' => ['article_id (update/delete)', 'category_id (create)', 'clang_id'],
            'fields' => array_combine(
                ArticlePayload::allFields(),
                array_map(static fn(string $f): string => $f, ArticlePayload::allFields()),
            ),
        ];

        $description['category'] = [
            'target' => ['category_id (update/delete)', 'parent_id (create)', 'clang_id'],
            'fields' => array_combine(
                CategoryPayload::allFields(),
                array_map(static fn(string $f): string => $f, CategoryPayload::allFields()),
            ),
        ];

        $metaFields = [];
        foreach (['art_', 'cat_', 'med_', 'clang_'] as $prefix) {
            foreach (MetaFieldRegistry::forPrefix($prefix) as $name => $definition) {
                $metaFields[$name] = $definition['title']
                    . (MetaFieldRegistry::isMultiValue($definition) ? ' (comma separated list)' : '');
            }
        }
        $description['meta'] = [
            'target' => ['carrier (article|category|media|clang)', 'article_id', 'category_id', 'filename', 'clang_id'],
            'fields' => $metaFields,
        ];

        $description['media'] = [
            'target' => ['filename', 'category_id (create only)'],
            'fields' => array_combine(
                MediaPayload::allFields(),
                array_map(static fn(string $f): string => $f, MediaPayload::allFields()),
            ),
        ];

        $yformFields = [];
        foreach (ChangeService::selectableYformTables() as $table) {
            foreach (YformFieldRegistry::forTable($table) as $name => $field) {
                $yformFields[$table . '.' . $name] = YformFieldRegistry::label($field)
                    . (YformFieldRegistry::acceptsList($field) ? ' (comma separated list)' : '');
            }
        }
        $description['yform'] = [
            'target' => ['table', 'dataset_id'],
            'fields' => $yformFields,
        ];

        // Only report types that are actually registered.
        return array_intersect_key($description, HandlerRegistry::all());
    }

    /**
     * @return array<string, string>
     */
    /**
     * Every module with the slots it actually uses.
     *
     * This exists because of a measured failure: an agent given only the
     * endpoint descriptions could not find out that module 2 reads value1 as a
     * headline and value2 as a teaser. It resorted to fetching the module over
     * a different addon's API and parsing the module's HTML for
     * REX_INPUT_VALUE[n] — which only worked because that token happened to
     * hold `modules/get`. A token with nothing but the change scopes could not
     * build a usable slice at all.
     *
     * `executes_php` is reported for the same reason: a proposal against such a
     * module is refused, and a caller should be able to see that before being
     * refused rather than after.
     *
     * @return array<int, array{name: string, slots: array<string, array{type: string, label: string|null}>, executes_php: bool}>
     */
    private static function describeModules(): array
    {
        $modules = [];

        foreach (rex_sql::factory()->getArray(
            'SELECT id, name FROM ' . rex::getTable('module') . ' ORDER BY name',
        ) as $row) {
            $id = (int) $row['id'];
            $slots = ModuleFieldMap::detectSlots($id);
            if ([] === $slots) {
                // A module reading no slots cannot carry proposed content.
                // Listing it would only invite a proposal that has nowhere to go.
                continue;
            }

            $modules[$id] = [
                'name' => (string) $row['name'],
                'slots' => $slots,
                'executes_php' => ChangeService::moduleExecutesPhp($id),
            ];
        }

        return $modules;
    }

    private static function describeSliceSlots(): array
    {
        $slots = [];
        foreach (SlicePayload::allSlots() as $slot) {
            $slots[$slot] = match (true) {
                str_starts_with($slot, 'medialist') => 'media filenames, comma separated',
                str_starts_with($slot, 'media') => 'media filename',
                str_starts_with($slot, 'linklist') => 'article ids, comma separated',
                str_starts_with($slot, 'link') => 'article id',
                default => 'text',
            };
        }

        return $slots;
    }
}
