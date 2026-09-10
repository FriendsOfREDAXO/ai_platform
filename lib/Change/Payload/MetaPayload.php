<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Payload;

use FriendsOfRedaxo\AiPlatform\Change\AbstractPatchPayload;
use FriendsOfRedaxo\AiPlatform\Change\Support\MetaFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Target\MetaTarget;
use InvalidArgumentException;

use function is_string;
use function str_starts_with;

/**
 * Metainfo values for one carrier.
 *
 * Built through a carrier-specific named constructor so the payload knows
 * its field prefix and can check every field against what metainfo actually
 * defines. Two things are validated at set time: the field exists, and the
 * prefix matches the carrier — `MetaPayload::forArticle()->set('med_alt', …)`
 * is refused rather than stored and then rejected hours later at review.
 *
 * Multi-value fields take a PHP list and are encoded to REDAXO's
 * pipe-wrapped format (`|a|b|`) by this class. A caller never sees that
 * format, so it cannot write `"a,b"` and produce data that looks stored but
 * reads back as one nonsense value.
 */
final class MetaPayload extends AbstractPatchPayload
{
    private ?string $prefix = null;

    public static function forArticle(): self
    {
        return self::withPrefix('art_');
    }

    public static function forCategory(): self
    {
        return self::withPrefix('cat_');
    }

    public static function forMedia(): self
    {
        return self::withPrefix('med_');
    }

    public static function forClang(): self
    {
        return self::withPrefix('clang_');
    }

    /**
     * Binds a payload to the same carrier as the given target.
     */
    public static function forTarget(MetaTarget $target): self
    {
        return self::withPrefix($target->getFieldPrefix());
    }

    private static function withPrefix(string $prefix): self
    {
        $payload = new self();
        $payload->prefix = $prefix;

        return $payload;
    }

    /**
     * A single-value metainfo field.
     */
    public function set(string $field, string|int|float|null $value): self
    {
        $definition = $this->requireField($field);

        if (MetaFieldRegistry::isMultiValue($definition)) {
            throw new InvalidArgumentException(sprintf(
                'Metainfo field "%s" holds multiple values — use setList() instead of set().',
                $field,
            ));
        }

        return $this->put($field, $value);
    }

    /**
     * A multi-value metainfo field (CHECKBOX, or SELECT declared multiple).
     *
     * @param list<string|int> $values
     */
    public function setList(string $field, array $values): self
    {
        $definition = $this->requireField($field);

        if (!MetaFieldRegistry::isMultiValue($definition)) {
            throw new InvalidArgumentException(sprintf(
                'Metainfo field "%s" holds a single value — use set() instead of setList().',
                $field,
            ));
        }

        $normalised = [];
        foreach ($values as $value) {
            $value = (string) $value;
            if ('' === $value) {
                continue;
            }
            if (str_contains($value, '|')) {
                throw new InvalidArgumentException(sprintf(
                    'Metainfo value "%s" must not contain a pipe — REDAXO uses it as the list separator.',
                    $value,
                ));
            }
            $normalised[] = $value;
        }

        return $this->put($field, MetaFieldRegistry::encodeMultiValue($normalised));
    }

    /**
     * A REX_MEDIA widget field. Expects a filename.
     */
    public function setMedia(string $field, ?string $filename): self
    {
        $this->requireField($field);

        return $this->put($field, $filename ?? '');
    }

    /**
     * A REX_LINK widget field. Expects an article id, or null to clear.
     */
    public function setLink(string $field, ?int $articleId): self
    {
        $this->requireField($field);

        if (null !== $articleId && $articleId < 1) {
            throw new InvalidArgumentException('Link target article id must be positive.');
        }

        return $this->put($field, $articleId ?? '');
    }

    /**
     * @return array{id: int, name: string, type_id: int, attributes: string, title: string}
     */
    private function requireField(string $field): array
    {
        if (null === $this->prefix) {
            throw new InvalidArgumentException(
                'MetaPayload needs a carrier: use MetaPayload::forArticle() / forCategory() / forMedia() / forClang().',
            );
        }

        if (!str_starts_with($field, $this->prefix)) {
            throw new InvalidArgumentException(sprintf(
                'Metainfo field "%s" does not match this carrier — expected a name starting with "%s".',
                $field,
                $this->prefix,
            ));
        }

        $definition = MetaFieldRegistry::field($this->prefix, $field);
        if (null === $definition) {
            throw new InvalidArgumentException(sprintf(
                'Unknown metainfo field "%s". Known fields for this carrier: %s',
                $field,
                implode(', ', array_keys(MetaFieldRegistry::forPrefix($this->prefix))) ?: '(none)',
            ));
        }

        return $definition;
    }

    /**
     * The prefix this payload was bound to, or null after rehydration from
     * storage — fromArray() intentionally keeps no carrier, because the
     * target already carries it and the handler re-validates on apply.
     */
    public function getPrefix(): ?string
    {
        return $this->prefix;
    }
}
