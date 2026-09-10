<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Target;

use FriendsOfRedaxo\AiPlatform\Change\AbstractTarget;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use InvalidArgumentException;
use rex_i18n;
use rex_url;
use rex_yform_manager_table;

/**
 * Points at a dataset in a YForm table, addressed by table name and id.
 *
 * The table name is validated for shape here and checked against the
 * configured allow list by the handler. Both matter: without the allow list
 * `YformTarget::existing('rex_ycom_user', 1)` would be a perfectly valid
 * change request, which is exactly the escalation this whole feature is
 * supposed to prevent.
 */
final class YformTarget extends AbstractTarget
{
    private function __construct(
        private readonly ChangeOperation $operation,
        private readonly string $table,
        private readonly ?int $datasetId = null,
    ) {
    }

    public static function changeType(): string
    {
        return 'yform';
    }

    public static function existing(string $table, int $datasetId): self
    {
        return new self(
            ChangeOperation::Update,
            self::assertTableName($table),
            self::assertPositive($datasetId, 'Dataset id'),
        );
    }

    public static function forDeletion(string $table, int $datasetId): self
    {
        return new self(
            ChangeOperation::Delete,
            self::assertTableName($table),
            self::assertPositive($datasetId, 'Dataset id'),
        );
    }

    public static function createIn(string $table): self
    {
        return new self(ChangeOperation::Create, self::assertTableName($table));
    }

    /**
     * Shape check only — whether the table may be written to at all is the
     * handler's decision, because that depends on runtime configuration.
     */
    private static function assertTableName(string $table): string
    {
        $table = trim($table);
        if (1 !== preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new InvalidArgumentException(sprintf('Invalid YForm table name "%s".', $table));
        }

        return $table;
    }

    public function operation(): ChangeOperation
    {
        return $this->operation;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getDatasetId(): ?int
    {
        return $this->datasetId;
    }

    public function loadTable(): ?rex_yform_manager_table
    {
        if (!class_exists(rex_yform_manager_table::class)) {
            return null;
        }

        return rex_yform_manager_table::get($this->table);
    }

    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'table' => $this->table,
            'dataset_id' => $this->datasetId,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            ChangeOperation::from(self::requireString($data, 'operation')),
            self::requireString($data, 'table'),
            self::optionalInt($data, 'dataset_id'),
        );
    }

    public function describe(): string
    {
        $table = $this->loadTable();
        $tableLabel = null === $table ? $this->table : $table->getName() . ' (' . $this->table . ')';

        if (null === $this->datasetId) {
            return rex_i18n::rawMsg('ai_platform_change_target_yform') . ' ' . $tableLabel . ' · ' . rex_i18n::rawMsg('ai_platform_change_target_new_dataset');
        }

        return rex_i18n::rawMsg('ai_platform_change_target_yform') . ' ' . $tableLabel . sprintf(' · #%d', $this->datasetId);
    }

    public function backendUrl(): ?string
    {
        $table = $this->loadTable();
        if (null === $table) {
            return null;
        }

        $params = ['table_name' => $this->table];
        if (null !== $this->datasetId) {
            $params['data_id'] = $this->datasetId;
            $params['func'] = 'edit';
        }

        return rex_url::backendPage('yform/manager/data_edit', $params);
    }
}
