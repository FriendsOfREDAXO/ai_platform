<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use function count;

/**
 * Outcome of approving several requests at once.
 *
 * Batches are applied request by request, each with its own final status —
 * not as one all-or-nothing transaction. A DB rollback would not undo what
 * the REDAXO services already did outside the database: content caches are
 * files, and the extension points they fire can send mail or clear a
 * yrewrite cache. Rolling back the rows while leaving that in place produces
 * a less consistent system than a partial success does, and the reviewer can
 * see exactly which items failed and why.
 */
final class BatchResult
{
    /** @var list<int> */
    private array $applied = [];
    /** @var array<int, string> */
    private array $failed = [];
    /** @var array<int, string> */
    private array $skipped = [];
    /** @var array<int, string> */
    private array $stale = [];

    public function recordApplied(int $id): void
    {
        $this->applied[] = $id;
    }

    public function recordFailed(int $id, string $reason): void
    {
        $this->failed[$id] = $reason;
    }

    /**
     * Not attempted — missing permission, already decided, handler gone.
     */
    public function recordSkipped(int $id, string $reason): void
    {
        $this->skipped[$id] = $reason;
    }

    /**
     * Blocked because the target moved since the proposal. Listed separately
     * because this is the one failure a reviewer can resolve by overriding.
     */
    public function recordStale(int $id, string $reason): void
    {
        $this->stale[$id] = $reason;
    }

    /**
     * @return list<int>
     */
    public function getApplied(): array
    {
        return $this->applied;
    }

    /**
     * @return array<int, string>
     */
    public function getFailed(): array
    {
        return $this->failed;
    }

    /**
     * @return array<int, string>
     */
    public function getSkipped(): array
    {
        return $this->skipped;
    }

    /**
     * @return array<int, string>
     */
    public function getStale(): array
    {
        return $this->stale;
    }

    public function appliedCount(): int
    {
        return count($this->applied);
    }

    public function failedCount(): int
    {
        return count($this->failed);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function staleCount(): int
    {
        return count($this->stale);
    }

    public function total(): int
    {
        return $this->appliedCount() + $this->failedCount() + $this->skippedCount() + $this->staleCount();
    }

    public function hasProblems(): bool
    {
        return [] !== $this->failed || [] !== $this->skipped || [] !== $this->stale;
    }
}
