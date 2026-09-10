<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use rex;
use rex_sql;

use function count;
use function in_array;
use function rex_request;

/**
 * Builds the filtered SQL for the inbox list.
 *
 * Kept out of the page file so the filter logic is testable and so the list
 * page does not assemble SQL inline. Filter values arrive from the request and
 * are only ever bound as parameters or matched against a fixed set — none of
 * them reach the query as text.
 */
final class ChangeQuery
{
    /**
     * @param list<string> $statuses
     */
    public function __construct(
        private readonly array $statuses = [],
        private readonly string $type = '',
        private readonly string $sourceKey = '',
        private readonly ?int $changesetId = null,
        private readonly string $search = '',
    ) {
    }

    /**
     * Reads the filter from the current request.
     */
    public static function fromRequest(): self
    {
        $status = (string) rex_request('status', 'string', 'open');

        $statuses = match ($status) {
            'open' => [ChangeStatus::Pending->value, ChangeStatus::Approved->value],
            'all' => [],
            default => null !== ChangeStatus::tryFrom($status) ? [$status] : [ChangeStatus::Pending->value, ChangeStatus::Approved->value],
        };

        $type = (string) rex_request('type', 'string', '');
        if ('' !== $type && !HandlerRegistry::has($type)) {
            $type = '';
        }

        $changesetId = (int) rex_request('changeset', 'int', 0);

        return new self(
            $statuses,
            $type,
            (string) rex_request('source', 'string', ''),
            $changesetId > 0 ? $changesetId : null,
            trim((string) rex_request('q', 'string', '')),
        );
    }

    /**
     * SQL for rex_list, which needs a plain query string. Values that cannot be
     * bound as parameters there are escaped through rex_sql::escape().
     */
    public function toListSql(): string
    {
        $sql = rex_sql::factory();
        $conditions = [];

        if ([] !== $this->statuses) {
            $quoted = array_map(static fn(string $s): string => $sql->escape($s), $this->statuses);
            $conditions[] = 'status IN (' . implode(', ', $quoted) . ')';
        }

        if ('' !== $this->type) {
            $conditions[] = 'type = ' . $sql->escape($this->type);
        }

        if ('' !== $this->sourceKey) {
            $conditions[] = 'source_key = ' . $sql->escape($this->sourceKey);
        }

        if (null !== $this->changesetId) {
            $conditions[] = 'changeset_id = ' . (int) $this->changesetId;
        }

        if ('' !== $this->search) {
            $needle = $sql->escapeLikeWildcards($this->search);
            $like = $sql->escape('%' . $needle . '%');
            $conditions[] = '(target_label LIKE ' . $like . ' OR reason LIKE ' . $like . ')';
        }

        $where = [] === $conditions ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return 'SELECT id, type, operation, target_label, status, source_label, source_key, changeset_id, reason, createdate, reviewed_by, reviewed_via'
            . ' FROM ' . rex::getTable('ai_change_request')
            . $where
            . ' ORDER BY createdate DESC, id DESC';
    }

    /**
     * Ids matching the current filter — the basis for "approve everything I am
     * looking at". Capped, so the action can never silently span more rows than
     * the batch limit allows.
     *
     * @return list<int>
     */
    public function matchingOpenIds(int $limit): array
    {
        $sql = rex_sql::factory();
        $conditions = ['status IN (:pending, :approved)'];
        $params = [
            ':pending' => ChangeStatus::Pending->value,
            ':approved' => ChangeStatus::Approved->value,
        ];

        if ('' !== $this->type) {
            $conditions[] = 'type = :type';
            $params[':type'] = $this->type;
        }
        if ('' !== $this->sourceKey) {
            $conditions[] = 'source_key = :source';
            $params[':source'] = $this->sourceKey;
        }
        if (null !== $this->changesetId) {
            $conditions[] = 'changeset_id = :changeset';
            $params[':changeset'] = $this->changesetId;
        }
        if ('' !== $this->search) {
            $conditions[] = '(target_label LIKE :needle OR reason LIKE :needle)';
            $params[':needle'] = '%' . $sql->escapeLikeWildcards($this->search) . '%';
        }

        $rows = $sql->getArray(
            'SELECT id FROM ' . rex::getTable('ai_change_request')
            . ' WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY createdate ASC, id ASC'
            . ($limit > 0 ? ' LIMIT ' . $limit : ''),
            $params,
        );

        return array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Distinct source keys with their labels, for the filter dropdown.
     *
     * @return array<string, string>
     */
    public static function knownSources(): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT source_key, MAX(source_label) AS label, COUNT(*) AS cnt FROM ' . rex::getTable('ai_change_request')
            . ' GROUP BY source_key ORDER BY cnt DESC',
        );

        $sources = [];
        foreach ($rows as $row) {
            $key = (string) $row['source_key'];
            $label = (string) ($row['label'] ?? '');
            $sources[$key] = '' !== $label ? $label . ' (' . $key . ')' : $key;
        }

        return $sources;
    }

    /**
     * Query string parameters representing this filter, for building links.
     *
     * @return array<string, string|int>
     */
    public function toParams(): array
    {
        $params = [];

        if ([] === $this->statuses) {
            $params['status'] = 'all';
        } elseif (1 === count($this->statuses)) {
            $params['status'] = $this->statuses[0];
        }
        if ('' !== $this->type) {
            $params['type'] = $this->type;
        }
        if ('' !== $this->sourceKey) {
            $params['source'] = $this->sourceKey;
        }
        if (null !== $this->changesetId) {
            $params['changeset'] = $this->changesetId;
        }
        if ('' !== $this->search) {
            $params['q'] = $this->search;
        }

        return $params;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSourceKey(): string
    {
        return $this->sourceKey;
    }

    public function getSearch(): string
    {
        return $this->search;
    }

    public function getChangesetId(): ?int
    {
        return $this->changesetId;
    }

    /**
     * @return list<string>
     */
    public function getStatuses(): array
    {
        return $this->statuses;
    }

    public function statusFilterValue(): string
    {
        if ([] === $this->statuses) {
            return 'all';
        }
        if (2 === count($this->statuses) && in_array(ChangeStatus::Pending->value, $this->statuses, true)) {
            return 'open';
        }

        return $this->statuses[0];
    }
}
