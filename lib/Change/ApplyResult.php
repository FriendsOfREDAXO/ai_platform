<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use function array_key_exists;

/**
 * Outcome of writing one change request.
 *
 * Carries the ids that only exist after the write — a newly created article,
 * slice or dataset — because a later revert needs them, and because the
 * reviewer wants a link to what was just created.
 *
 * `valuesAfter` is filled in by ChangeService after a successful apply, by
 * reading the target again. That is not belt-and-braces: REDAXO normalises
 * some fields on write. Article and category priorities are relative
 * positions, and rex_sql_util::organizePriorities() renumbers all siblings
 * gapless — so a proposal asking for priority 6 in a category holding one
 * article results in priority 1. Recording what was actually stored means the
 * reviewer sees the real outcome instead of the request's wish.
 *
 * `touchedArticles` collects article/language pairs whose content cache has
 * to be regenerated. It is deliberately reported rather than flushed inline:
 * applying a changeset of fifty slices in one article must regenerate that
 * article once, not fifty times.
 */
final class ApplyResult
{
    /**
     * @param array<string, scalar|null> $createdIds e.g. ['slice_id' => 412]
     * @param list<array{article_id: int, clang_id: int}> $touchedArticles
     * @param list<string> $messages
     */
    private function __construct(
        public readonly bool $success,
        public readonly array $createdIds = [],
        public readonly array $touchedArticles = [],
        public readonly array $messages = [],
        public readonly ?string $error = null,
        public readonly ?array $valuesAfter = null,
    ) {
    }

    /**
     * @param array<string, scalar|null> $createdIds
     * @param list<array{article_id: int, clang_id: int}> $touchedArticles
     * @param list<string> $messages
     */
    public static function ok(array $createdIds = [], array $touchedArticles = [], array $messages = []): self
    {
        return new self(true, $createdIds, $touchedArticles, $messages);
    }

    public static function failed(string $error): self
    {
        return new self(false, [], [], [], $error);
    }

    /**
     * Returns a copy carrying the target's values as they are after the write.
     *
     * @param array<string, scalar|null>|null $valuesAfter
     */
    public function withValuesAfter(?array $valuesAfter): self
    {
        return new self(
            $this->success,
            $this->createdIds,
            $this->touchedArticles,
            $this->messages,
            $this->error,
            $valuesAfter,
        );
    }

    /**
     * Fields whose stored value ends up different from what was proposed —
     * REDAXO normalising a priority, for instance. Empty when everything
     * landed as asked.
     *
     * @param array<string, scalar|null> $proposed
     * @return array<string, array{proposed: scalar|null, stored: scalar|null}>
     */
    public function deviations(array $proposed): array
    {
        if (null === $this->valuesAfter) {
            return [];
        }

        $deviations = [];
        foreach ($proposed as $key => $value) {
            if (!array_key_exists($key, $this->valuesAfter)) {
                continue;
            }
            $stored = $this->valuesAfter[$key];
            if ((string) $stored !== (string) $value) {
                $deviations[(string) $key] = ['proposed' => $value, 'stored' => $stored];
            }
        }

        return $deviations;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'created_ids' => $this->createdIds,
            'touched_articles' => $this->touchedArticles,
            'messages' => $this->messages,
            'error' => $this->error,
            'values_after' => $this->valuesAfter,
        ];
    }
}
