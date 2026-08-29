<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use InvalidArgumentException;
use rex;
use rex_article;
use rex_clang;
use rex_sql;

use function is_numeric;
use function is_scalar;

/**
 * Shared plumbing for target value objects: reading persisted arrays back
 * in safely, and resolving human-readable labels for the backend.
 */
abstract class AbstractTarget implements TargetInterface
{
    /**
     * @param array<string, mixed> $data
     */
    final protected static function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Target field "%s" must be numeric.', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    final protected static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    final protected static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value) || '' === (string) $value) {
            throw new InvalidArgumentException(sprintf('Target field "%s" must be a non-empty string.', $key));
        }

        return (string) $value;
    }

    final protected static function assertPositive(int $value, string $what): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer, got %d.', $what, $value));
        }

        return $value;
    }

    /**
     * Validates that the language exists. Prevents a request from being
     * stored against a clang that was removed in the meantime.
     */
    final protected static function assertClang(int $clangId): int
    {
        if (!rex_clang::exists($clangId)) {
            throw new InvalidArgumentException(sprintf('Language with id %d does not exist.', $clangId));
        }

        return $clangId;
    }

    /**
     * "Artikel 12 »Startseite« (de)" — falls back to the bare id when the
     * article is gone, because targets must stay describable after deletion.
     */
    final protected static function describeArticle(int $articleId, int $clangId): string
    {
        $label = sprintf('#%d', $articleId);

        $article = rex_article::get($articleId, $clangId);
        if (null !== $article) {
            $label = sprintf('#%d »%s«', $articleId, $article->getName());
        }

        $clang = rex_clang::get($clangId);
        if (null !== $clang) {
            $label .= sprintf(' (%s)', $clang->getCode());
        }

        return $label;
    }

    /**
     * Module name for display. There is no entity class for modules in the
     * REDAXO core — `rex_module` only carries id and key — so the name is
     * read straight from the table.
     */
    final protected static function describeModule(int $moduleId): ?string
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT name FROM ' . rex::getTable('module') . ' WHERE id = :id',
            [':id' => $moduleId],
        );

        $name = $rows[0]['name'] ?? null;

        return is_scalar($name) && '' !== (string) $name ? (string) $name : null;
    }
}
