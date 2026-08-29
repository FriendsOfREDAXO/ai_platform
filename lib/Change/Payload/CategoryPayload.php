<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Payload;

use FriendsOfRedaxo\AiPlatform\Change\AbstractPatchPayload;
use InvalidArgumentException;

/**
 * Core fields of a category.
 *
 * The setters read like the article ones, but the stored keys are REDAXO's
 * category columns: `catname` and `catpriority`. rex_category_service really
 * does patch per field (it checks isset), which is why this class exists
 * separately from ArticlePayload — merging them would force one of the two
 * semantics onto the other.
 */
final class CategoryPayload extends AbstractPatchPayload
{
    public function name(string $name): self
    {
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidArgumentException('Category name must not be empty.');
        }

        return $this->put('catname', $name);
    }

    public function priority(int $priority): self
    {
        if ($priority < 1) {
            throw new InvalidArgumentException('Category priority must be 1 or greater.');
        }

        return $this->put('catpriority', $priority);
    }

    /**
     * 0 = offline, 1 = online. Applied via rex_category_service::categoryStatus().
     */
    public function status(int $status): self
    {
        if (0 !== $status && 1 !== $status) {
            throw new InvalidArgumentException('Category status must be 0 (offline) or 1 (online).');
        }

        return $this->put('status', $status);
    }

    /**
     * Fields handed to rex_category_service::editCategory().
     *
     * @return list<string>
     */
    public static function serviceFields(): array
    {
        return ['catname', 'catpriority'];
    }

    /**
     * @return list<string>
     */
    public static function allFields(): array
    {
        return ['catname', 'catpriority', 'status'];
    }
}
