<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use DateTimeImmutable;
use Exception;
use rex;
use rex_sql;
use RuntimeException;

use function count;
use function is_array;
use function is_string;

/**
 * Persistence for change requests and changesets.
 *
 * The only place that speaks SQL for this feature, and the only place that
 * deals in arrays: rows go in as JSON and come back out as hydrated target
 * and payload objects, so nothing downstream has to guess what a column
 * contains.
 */
final class ChangeRequestStore
{
    public function table(): string
    {
        return rex::getTable('ai_change_request');
    }

    public function changesetTable(): string
    {
        return rex::getTable('ai_changeset');
    }

    // -----------------------------------------------------------------
    // Change requests
    // -----------------------------------------------------------------

    /**
     * @return int the new request id
     */
    public function insert(ChangeRequest $request): int
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->table());
        $sql->setValue('changeset_id', $request->changesetId);
        $sql->setValue('type', $request->type);
        $sql->setValue('operation', $request->operation->value);
        $sql->setValue('target', self::encode($request->target->toArray()));
        $sql->setValue('target_label', mb_substr($request->targetLabel, 0, 255));
        $sql->setValue('payload', self::encode($request->payload->toArray()));
        $sql->setValue('snapshot_before', null === $request->snapshotBefore ? null : self::encode($request->snapshotBefore));
        $sql->setValue('base_hash', $request->baseHash);
        $sql->setValue('context_snapshot', null === $request->contextSnapshot ? null : self::encode($request->contextSnapshot));
        $sql->setValue('context_hash', $request->contextHash);
        $sql->setValue('reason', $request->reason);
        $sql->setValue('status', $request->status->value);
        $sql->setValue('source_channel', $request->source->channel);
        $sql->setValue('source_key', mb_substr($request->source->key, 0, 100));
        $sql->setValue('source_label', mb_substr($request->source->displayName(), 0, 255));
        $sql->setValue('source_user_type', $request->source->userType);
        $sql->setValue('source_user_id', $request->source->userId);
        $sql->setValue('source_meta', [] === $request->source->meta ? null : self::encode($request->source->meta));
        $sql->addGlobalCreateFields();
        $sql->insert();

        return (int) $sql->getLastId();
    }

    public function find(int $id): ?ChangeRequest
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->table() . ' WHERE id = :id',
            [':id' => $id],
        );

        if ([] === $rows) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    /**
     * Same as find(), but skips requests whose handler is no longer
     * registered — those cannot be acted upon meaningfully.
     */
    public function findLoadable(int $id): ?ChangeRequest
    {
        $request = $this->find($id);

        return null !== $request && HandlerRegistry::has($request->type) ? $request : null;
    }

    /**
     * @param list<int> $ids
     * @return list<ChangeRequest>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ([] === $ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->table() . ' WHERE id IN (' . $placeholders . ') ORDER BY id',
            $ids,
        );

        return $this->hydrateAll($rows);
    }

    /**
     * @return list<ChangeRequest>
     */
    public function findByChangeset(int $changesetId, ?ChangeStatus $status = null): array
    {
        $where = 'changeset_id = :changeset';
        $params = [':changeset' => $changesetId];

        if (null !== $status) {
            $where .= ' AND status = :status';
            $params[':status'] = $status->value;
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->table() . ' WHERE ' . $where . ' ORDER BY id',
            $params,
        );

        return $this->hydrateAll($rows);
    }

    /**
     * Other open requests aiming at the same target — used to mark earlier
     * proposals superseded when a newer one arrives.
     *
     * @return list<int>
     */
    public function findOpenIdsForTarget(string $type, TargetInterface $target, ?int $exceptId = null): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT id, target FROM ' . $this->table()
            . ' WHERE type = :type AND status IN (:pending, :approved)',
            [
                ':type' => $type,
                ':pending' => ChangeStatus::Pending->value,
                ':approved' => ChangeStatus::Approved->value,
            ],
        );

        $needle = self::encode($target->toArray());
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (null !== $exceptId && $id === $exceptId) {
                continue;
            }
            if ((string) $row['target'] === $needle) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function countOpenForSource(string $sourceKey): int
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT COUNT(*) AS cnt FROM ' . $this->table()
            . ' WHERE source_key = :key AND status IN (:pending, :approved)',
            [
                ':key' => $sourceKey,
                ':pending' => ChangeStatus::Pending->value,
                ':approved' => ChangeStatus::Approved->value,
            ],
        );

        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Counts per status, for the menu badge and the filter bar.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT status, COUNT(*) AS cnt FROM ' . $this->table() . ' GROUP BY status',
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    public function countOpen(): int
    {
        $counts = $this->countByStatus();

        return ($counts[ChangeStatus::Pending->value] ?? 0) + ($counts[ChangeStatus::Approved->value] ?? 0);
    }

    public function updateStatus(
        int $id,
        ChangeStatus $status,
        ?int $reviewedBy = null,
        ?string $reviewNote = null,
        ?string $reviewedVia = null,
    ): void {
        $sql = rex_sql::factory();
        $sql->setTable($this->table());
        $sql->setWhere(['id' => $id]);
        $sql->setValue('status', $status->value);

        // A decision without a user is a real case (an API token has none), so
        // the timestamp hangs on the channel rather than on the user id —
        // otherwise a token-approved request would look never-reviewed.
        if (null !== $reviewedVia) {
            $sql->setValue('reviewed_via', $reviewedVia);
            $sql->setDateTimeValue('reviewed_at', time());
        }
        if (null !== $reviewedBy) {
            $sql->setValue('reviewed_by', $reviewedBy);
            $sql->setDateTimeValue('reviewed_at', time());
        }
        if (null !== $reviewNote) {
            $sql->setValue('review_note', $reviewNote);
        }

        $sql->addGlobalUpdateFields();
        $sql->update();
    }

    public function recordApplied(int $id, ApplyResult $result): void
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->table());
        $sql->setWhere(['id' => $id]);
        $sql->setValue('status', ChangeStatus::Applied->value);
        $sql->setDateTimeValue('applied_at', time());
        $sql->setValue('apply_result', self::encode($result->toArray()));
        $sql->setValue('apply_error', null);
        $sql->addGlobalUpdateFields();
        $sql->update();
    }

    public function recordFailure(int $id, string $error): void
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->table());
        $sql->setWhere(['id' => $id]);
        $sql->setValue('status', ChangeStatus::Failed->value);
        $sql->setValue('apply_error', mb_substr($error, 0, 4000));
        $sql->addGlobalUpdateFields();
        $sql->update();
    }

    public function saveEditedPayload(int $id, PayloadInterface $payload): void
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->table());
        $sql->setWhere(['id' => $id]);
        $sql->setValue('payload_edited', self::encode($payload->toArray()));
        $sql->addGlobalUpdateFields();
        $sql->update();
    }

    /**
     * @param list<int> $ids
     */
    public function markSuperseded(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        rex_sql::factory()->setQuery(
            'UPDATE ' . $this->table() . ' SET status = ? WHERE id IN (' . $placeholders . ')',
            array_merge([ChangeStatus::Superseded->value], array_map('intval', $ids)),
        );
    }

    /**
     * Deletes finished requests older than the given number of days. Open
     * requests are never touched — a pending proposal has to be decided, not
     * silently dropped.
     *
     * @return int number of deleted rows
     */
    /**
     * Frees space without destroying the record.
     *
     * This used to DELETE decided rows. It no longer does: the row is the proof
     * of who decided what, and once approvals can arrive over the API that proof
     * is the only thing standing between an automated write and an unexplainable
     * change. What actually takes up room is the JSON — payload, snapshot and
     * context of every proposal — so that is what gets emptied. Who, when, which
     * target, which decision, through which channel stays forever.
     *
     * Rows already thinned are skipped, so repeated runs stay cheap and the
     * returned count means "thinned this time".
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . $this->table() . ' SET payload = :empty, payload_edited = NULL,'
            . ' snapshot_before = NULL, context_snapshot = NULL'
            . ' WHERE status NOT IN (:pending, :approved)'
            . ' AND createdate < DATE_SUB(NOW(), INTERVAL :days DAY)'
            . ' AND (snapshot_before IS NOT NULL OR context_snapshot IS NOT NULL OR payload <> :empty)',
            [
                ':empty' => '{}',
                ':pending' => ChangeStatus::Pending->value,
                ':approved' => ChangeStatus::Approved->value,
                ':days' => $days,
            ],
        );

        return $sql->getRows();
    }

    // -----------------------------------------------------------------
    // Changesets
    // -----------------------------------------------------------------

    public function insertChangeset(Changeset $changeset): int
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->changesetTable());
        $sql->setValue('token', $changeset->token);
        $sql->setValue('client_key', null === $changeset->clientKey ? null : mb_substr($changeset->clientKey, 0, 100));
        $sql->setValue('title', mb_substr($changeset->title, 0, 255));
        $sql->setValue('description', $changeset->description);
        $sql->setValue('status', $changeset->status);
        $sql->setValue('source_channel', $changeset->source->channel);
        $sql->setValue('source_key', mb_substr($changeset->source->key, 0, 100));
        $sql->setValue('source_label', mb_substr($changeset->source->displayName(), 0, 255));
        $sql->setValue('source_user_type', $changeset->source->userType);
        $sql->setValue('source_user_id', $changeset->source->userId);
        $sql->setValue('source_meta', [] === $changeset->source->meta ? null : self::encode($changeset->source->meta));
        $sql->addGlobalCreateFields();
        $sql->insert();

        return (int) $sql->getLastId();
    }

    public function findChangeset(int $id): ?Changeset
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->changesetTable() . ' WHERE id = :id',
            [':id' => $id],
        );

        return [] === $rows ? null : $this->hydrateChangeset($rows[0]);
    }

    public function findChangesetByToken(string $token): ?Changeset
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->changesetTable() . ' WHERE token = :token',
            [':token' => $token],
        );

        return [] === $rows ? null : $this->hydrateChangeset($rows[0]);
    }

    /**
     * Looks up a source's own package by the name it chose.
     */
    public function findChangesetByClientKey(string $sourceKey, string $clientKey): ?Changeset
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . $this->changesetTable() . ' WHERE source_key = :source AND client_key = :client',
            [':source' => $sourceKey, ':client' => $clientKey],
        );

        return [] === $rows ? null : $this->hydrateChangeset($rows[0]);
    }

    public function updateChangesetStatus(int $id, string $status): void
    {
        $sql = rex_sql::factory();
        $sql->setTable($this->changesetTable());
        $sql->setWhere(['id' => $id]);
        $sql->setValue('status', $status);
        $sql->addGlobalUpdateFields();
        $sql->update();
    }

    /**
     * Member counts of a changeset: total and still open.
     *
     * @return array{total: int, open: int, applied: int, failed: int}
     */
    public function changesetCounts(int $changesetId): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT status, COUNT(*) AS cnt FROM ' . $this->table() . ' WHERE changeset_id = :id GROUP BY status',
            [':id' => $changesetId],
        );

        $counts = ['total' => 0, 'open' => 0, 'applied' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $status = ChangeStatus::tryFrom((string) $row['status']);
            $count = (int) $row['cnt'];
            $counts['total'] += $count;
            if (null === $status) {
                continue;
            }
            if ($status->isOpen()) {
                $counts['open'] += $count;
            }
            if (ChangeStatus::Applied === $status) {
                $counts['applied'] += $count;
            }
            if (ChangeStatus::Failed === $status) {
                $counts['failed'] += $count;
            }
        }

        return $counts;
    }

    // -----------------------------------------------------------------
    // Hydration
    // -----------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ChangeRequest>
     */
    private function hydrateAll(array $rows): array
    {
        $requests = [];
        foreach ($rows as $row) {
            try {
                $requests[] = $this->hydrate($row);
            } catch (Exception) {
                // A row whose handler was uninstalled, or whose target no
                // longer parses, must not break a whole list view. It is
                // skipped here and reported by the integrity check on the
                // settings page.
                continue;
            }
        }

        return $requests;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): ChangeRequest
    {
        $type = (string) $row['type'];
        $handler = HandlerRegistry::require($type);

        /** @var class-string<TargetInterface> $targetClass */
        $targetClass = $handler->targetClass();
        /** @var class-string<PayloadInterface> $payloadClass */
        $payloadClass = $handler->payloadClass();

        $target = $targetClass::fromArray(self::decode($row['target'] ?? null) ?? []);
        $payload = $payloadClass::fromArray(self::decode($row['payload'] ?? null) ?? []);

        $editedRaw = self::decode($row['payload_edited'] ?? null);
        $payloadEdited = null === $editedRaw ? null : $payloadClass::fromArray($editedRaw);

        $status = ChangeStatus::tryFrom((string) $row['status'])
            ?? throw new RuntimeException(sprintf('Unknown change status "%s" in request %s.', (string) $row['status'], (string) ($row['id'] ?? '?')));

        $operation = ChangeOperation::tryFrom((string) $row['operation'])
            ?? throw new RuntimeException(sprintf('Unknown change operation "%s" in request %s.', (string) $row['operation'], (string) ($row['id'] ?? '?')));

        return new ChangeRequest(
            id: (int) $row['id'],
            type: $type,
            operation: $operation,
            target: $target,
            payload: $payload,
            payloadEdited: $payloadEdited,
            snapshotBefore: self::decode($row['snapshot_before'] ?? null),
            baseHash: (string) ($row['base_hash'] ?? ''),
            contextSnapshot: self::decode($row['context_snapshot'] ?? null),
            contextHash: isset($row['context_hash']) && null !== $row['context_hash'] ? (string) $row['context_hash'] : null,
            reason: (string) ($row['reason'] ?? ''),
            status: $status,
            source: $this->hydrateSource($row),
            changesetId: isset($row['changeset_id']) && null !== $row['changeset_id'] ? (int) $row['changeset_id'] : null,
            targetLabel: (string) ($row['target_label'] ?? ''),
            reviewedBy: isset($row['reviewed_by']) && null !== $row['reviewed_by'] ? (int) $row['reviewed_by'] : null,
            reviewedAt: self::toDate($row['reviewed_at'] ?? null),
            reviewNote: isset($row['review_note']) ? (string) $row['review_note'] : null,
            reviewedVia: isset($row['reviewed_via']) && '' !== (string) $row['reviewed_via']
                ? (string) $row['reviewed_via']
                : null,
            appliedAt: self::toDate($row['applied_at'] ?? null),
            applyResult: self::decode($row['apply_result'] ?? null),
            applyError: isset($row['apply_error']) && null !== $row['apply_error'] ? (string) $row['apply_error'] : null,
            createdAt: self::toDate($row['createdate'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateChangeset(array $row): Changeset
    {
        $counts = $this->changesetCounts((int) $row['id']);

        return new Changeset(
            id: (int) $row['id'],
            token: (string) $row['token'],
            clientKey: isset($row['client_key']) && null !== $row['client_key'] ? (string) $row['client_key'] : null,
            title: (string) ($row['title'] ?? ''),
            description: (string) ($row['description'] ?? ''),
            status: (string) ($row['status'] ?? Changeset::STATUS_OPEN),
            source: $this->hydrateSource($row),
            createdAt: self::toDate($row['createdate'] ?? null),
            requestCount: $counts['total'],
            openCount: $counts['open'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSource(array $row): Source
    {
        return Source::fromArray([
            'channel' => $row['source_channel'] ?? Source::CHANNEL_CUSTOM,
            'key' => $row['source_key'] ?? 'unknown',
            'label' => $row['source_label'] ?? '',
            'user_type' => $row['source_user_type'] ?? null,
            'user_id' => $row['source_user_id'] ?? null,
            'meta' => self::decode($row['source_meta'] ?? null) ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $raw): ?array
    {
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function toDate(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || '' === $raw || str_starts_with($raw, '0000')) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }
}
