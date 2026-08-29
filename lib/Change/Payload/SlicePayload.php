<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Payload;

use FriendsOfRedaxo\AiPlatform\Change\AbstractPatchPayload;
use FriendsOfRedaxo\AiPlatform\Change\Support\ModuleFieldMap;
use InvalidArgumentException;

use function array_map;
use function count;
use function implode;
use function is_int;

/**
 * Values for a content slice.
 *
 * Slot ranges are taken from the actual `rex_article_slice` table:
 * value1..value20, and media / medialist / link / linklist 1..10 each. They
 * are enforced in the setters, so an out-of-range slot fails where it is
 * written, not somewhere inside an SQL error later.
 *
 * (Note for anyone comparing with the api addon: its slice routes only cover
 * value1..value19, so value20 is unreachable over REST. This class covers
 * all twenty.)
 *
 * List-valued slots take PHP arrays and serialise themselves — a caller
 * never builds the comma-separated string, and therefore cannot get the
 * format wrong.
 */
final class SlicePayload extends AbstractPatchPayload
{
    public const VALUE_SLOTS = 20;
    public const LIST_SLOTS = 10;

    private ?int $moduleId = null;

    /**
     * Binds the payload to a module so {@see field()} can resolve names via
     * ModuleFieldMap. Only needed for named access; slot setters work
     * without it.
     */
    public static function forModule(int $moduleId): self
    {
        $payload = new self();
        $payload->moduleId = $moduleId;

        return $payload;
    }

    /**
     * A REX_VALUE slot, 1..20.
     */
    public function value(int $slot, ?string $value): self
    {
        self::assertSlot($slot, self::VALUE_SLOTS, 'REX_VALUE');

        return $this->put('value' . $slot, $value);
    }

    /**
     * A single media file (REX_MEDIA), 1..10. Expects a filename.
     */
    public function media(int $slot, ?string $filename): self
    {
        self::assertSlot($slot, self::LIST_SLOTS, 'REX_MEDIA');

        return $this->put('media' . $slot, $filename);
    }

    /**
     * A media list (REX_MEDIALIST), 1..10.
     *
     * @param list<string> $filenames
     */
    public function mediaList(int $slot, array $filenames): self
    {
        self::assertSlot($slot, self::LIST_SLOTS, 'REX_MEDIALIST');

        return $this->put('medialist' . $slot, self::joinStrings($filenames, 'media list'));
    }

    /**
     * A single article link (REX_LINK), 1..10.
     */
    public function link(int $slot, ?int $articleId): self
    {
        self::assertSlot($slot, self::LIST_SLOTS, 'REX_LINK');

        if (null !== $articleId && $articleId < 1) {
            throw new InvalidArgumentException('Link target article id must be positive.');
        }

        return $this->put('link' . $slot, $articleId);
    }

    /**
     * An article link list (REX_LINKLIST), 1..10.
     *
     * @param list<int> $articleIds
     */
    public function linkList(int $slot, array $articleIds): self
    {
        self::assertSlot($slot, self::LIST_SLOTS, 'REX_LINKLIST');

        foreach ($articleIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Link list entries must be positive article ids.');
            }
        }

        return $this->put('linklist' . $slot, implode(',', array_map('strval', $articleIds)));
    }

    /**
     * Named access, resolved through the module's field map.
     *
     * Throws when the module has no map or does not know the name — writing
     * to a guessed slot would silently corrupt content, which is worse than
     * refusing.
     */
    public function field(string $name, string|int|null $value): self
    {
        if (null === $this->moduleId) {
            throw new InvalidArgumentException(
                'Named slice fields need a module: use SlicePayload::forModule($moduleId) instead of new SlicePayload().',
            );
        }

        $slot = ModuleFieldMap::resolveSlot($this->moduleId, $name);
        if (null === $slot) {
            throw new InvalidArgumentException(sprintf(
                'Module %d has no field named "%s". Register a field map via the AI_PLATFORM_MODULE_FIELDS extension point, or use the slot setters.',
                $this->moduleId,
                $name,
            ));
        }

        return $this->put($slot, $value);
    }

    /**
     * @param list<string> $values
     */
    private static function joinStrings(array $values, string $what): string
    {
        foreach ($values as $value) {
            if (!\is_string($value) || '' === $value) {
                throw new InvalidArgumentException(sprintf('Entries of a %s must be non-empty strings.', $what));
            }
            if (str_contains($value, ',')) {
                throw new InvalidArgumentException(sprintf('Entries of a %s must not contain commas — REDAXO stores them comma separated.', $what));
            }
        }

        return implode(',', $values);
    }

    /**
     * Slot names in table order, for building diffs and forms.
     *
     * @return list<string>
     */
    public static function allSlots(): array
    {
        $slots = [];
        for ($i = 1; $i <= self::VALUE_SLOTS; ++$i) {
            $slots[] = 'value' . $i;
        }
        foreach (['media', 'medialist', 'link', 'linklist'] as $prefix) {
            for ($i = 1; $i <= self::LIST_SLOTS; ++$i) {
                $slots[] = $prefix . $i;
            }
        }

        return $slots;
    }

    /**
     * Whether a column name is a writable slice slot. Guards the apply step
     * against anything that is not content — id, article_id, revision,
     * createuser and friends must never be reachable from a payload.
     */
    public static function isSlot(string $name): bool
    {
        return \in_array($name, self::allSlots(), true);
    }

    public function count(): int
    {
        return count($this->keys());
    }
}
