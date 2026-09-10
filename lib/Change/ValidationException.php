<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use RuntimeException;

/**
 * A change request cannot be written as described.
 *
 * Raised by handlers from validate(), both when a request is proposed (so
 * the caller gets immediate feedback) and again right before it is applied
 * (because the world moves: a module gets deleted, a metainfo field is
 * removed, a YForm column is dropped).
 *
 * Carries per-field messages where the underlying service provides them —
 * YForm's dataset validation does, for instance — so the reviewer sees which
 * field is the problem rather than just "invalid".
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $fieldErrors field name => message
     */
    public function __construct(
        string $message,
        private readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, string> $fieldErrors
     */
    public static function withFields(string $message, array $fieldErrors): self
    {
        return new self($message, $fieldErrors);
    }

    /**
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }

    public function hasFieldErrors(): bool
    {
        return [] !== $this->fieldErrors;
    }
}
