<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Handler;

use FriendsOfRedaxo\AiPlatform\Change\AbstractHandler;
use FriendsOfRedaxo\AiPlatform\Change\ApplyResult;
use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangeRequest;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\Diff\DiffField;
use FriendsOfRedaxo\AiPlatform\Change\Payload\YformPayload;
use FriendsOfRedaxo\AiPlatform\Change\Support\YformFieldRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Target\YformTarget;
use FriendsOfRedaxo\AiPlatform\Change\TargetInterface;
use FriendsOfRedaxo\AiPlatform\Change\ValidationException;
use rex_i18n;
use rex_sql;
use rex_user;
use rex_yform_manager_dataset;
use rex_yform_manager_table_authorization;
use Throwable;

use function array_key_exists;
use function is_array;
use function is_numeric;

/**
 * YForm datasets.
 *
 * The best-informed handler of the six: YForm exposes its schema fully, so
 * field names are checked against reality, labels in the diff are the real
 * ones, and the apply step runs YForm's own validation — a proposal faces
 * exactly the rules a human form submission would. Its snapshot mechanism
 * also makes this the one type where revert is close to free.
 *
 * The table allow list is not optional. A YForm target can name any table in
 * the database, so without it `YformTarget::existing('rex_ycom_user', 1)`
 * would be a valid change request — which is precisely the escalation this
 * feature exists to prevent. An empty list therefore means "no table", not
 * "every table".
 */
final class YformHandler extends AbstractHandler
{
    public function getType(): string
    {
        return 'yform';
    }

    public function targetClass(): string
    {
        return YformTarget::class;
    }

    public function payloadClass(): string
    {
        return YformPayload::class;
    }

    /**
     * Reads the row straight from the table, not through
     * rex_yform_manager_dataset.
     *
     * The dataset class caches instances per (table, id) — both get() and
     * getRaw() go through the same instance pool. A snapshot taken after
     * anything else in the request touched that dataset would come back with the
     * cached values, and stale detection would report "unchanged" for a row
     * somebody had just edited. Reading the columns directly is cache-free and
     * gives the raw stored values, which is exactly what a fingerprint wants.
     */
    public function readCurrent(TargetInterface $target): ?array
    {
        $target = $this->requireTarget($target);
        $datasetId = $target->getDatasetId();
        if (null === $datasetId || !YformFieldRegistry::isAvailable()) {
            return null;
        }

        $fields = YformFieldRegistry::forTable($target->getTable());
        if ([] === $fields) {
            return null;
        }

        $columns = implode(', ', array_map(
            static fn(string $name): string => '`' . $name . '`',
            array_keys($fields),
        ));

        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT ' . $columns . ' FROM ' . rex_sql::factory()->escapeIdentifier($target->getTable()) . ' WHERE id = :id',
                [':id' => $datasetId],
            );
        } catch (Throwable) {
            return null;
        }

        if ([] === $rows) {
            return null;
        }

        $values = [];
        foreach (array_keys($fields) as $name) {
            $values[$name] = self::stringify($rows[0][$name] ?? null);
        }

        return $values;
    }

    /**
     * How many rows the table holds.
     *
     * Coarse on purpose. The dataset's own fields are already covered by the
     * base fingerprint — the user's case of "the dataset changed meanwhile" is
     * caught there. What this adds is the create case, where there is no dataset
     * yet: a proposal made against an empty table means something different once
     * it has fifty rows, and for tables with a unique constraint it may not even
     * be applicable any more.
     */
    public function readContext(TargetInterface $target): ?array
    {
        $target = $this->requireTarget($target);

        if (!YformFieldRegistry::isAvailable() || [] === YformFieldRegistry::forTable($target->getTable())) {
            return null;
        }

        try {
            $rows = rex_sql::factory()->getArray(
                'SELECT COUNT(*) AS cnt FROM ' . rex_sql::factory()->escapeIdentifier($target->getTable()),
            );
        } catch (Throwable) {
            return null;
        }

        return ['row_count' => (int) ($rows[0]['cnt'] ?? 0)];
    }

    /**
     * Datasets referenced by relation fields.
     *
     * A be_manager_relation holds ids into another table. Those rows can be
     * deleted between proposal and approval, and YForm will happily store the
     * stale id.
     *
     * @return list<string>
     */
    public function checkReferences(ChangeRequest $request): array
    {
        $target = $this->requireTarget($request->target);
        $table = $target->getTable();

        if (!YformFieldRegistry::isAvailable()) {
            return [rex_i18n::rawMsg('ai_platform_change_err_yform_missing')];
        }

        $fields = YformFieldRegistry::forTable($table);
        if ([] === $fields) {
            return [rex_i18n::rawMsg('ai_platform_change_err_yform_table_gone', $table)];
        }

        if (ChangeOperation::Delete === $request->operation) {
            return [];
        }

        $problems = [];

        foreach ($request->effectivePayload()->toArray() as $name => $value) {
            $name = (string) $name;
            $field = $fields[$name] ?? null;
            if (null === $field || 'be_manager_relation' !== (string) $field->getTypeName()) {
                continue;
            }

            $text = self::stringify($value);
            if (null === $text || '' === trim($text)) {
                continue;
            }

            $relatedTable = (string) $field->getElement('table');
            if ('' === $relatedTable) {
                continue;
            }

            foreach (YformFieldRegistry::decodeList($text) as $relatedId) {
                if (!is_numeric($relatedId)) {
                    continue;
                }
                try {
                    $exists = [] !== rex_sql::factory()->getArray(
                        'SELECT id FROM ' . rex_sql::factory()->escapeIdentifier($relatedTable) . ' WHERE id = :id',
                        [':id' => (int) $relatedId],
                    );
                } catch (Throwable) {
                    // Unreadable related table is itself a broken reference.
                    $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_yform_table_unreadable', $name, $relatedTable);
                    break;
                }
                if (!$exists) {
                    $problems[] = rex_i18n::rawMsg('ai_platform_change_ref_yform_dataset_gone', $name, $relatedTable, (string) $relatedId);
                }
            }
        }

        return $problems;
    }

    public function validate(ChangeRequest $request): void
    {
        $target = $this->requireTarget($request->target);
        $table = $target->getTable();

        if (!YformFieldRegistry::isAvailable()) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_yform_missing'));
        }

        // The closed door, checked on every path.
        if (!ChangeService::isYformTableAllowed($table)) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_yform_table_not_allowed', $table));
        }

        $fields = YformFieldRegistry::forTable($table);
        if ([] === $fields) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_yform_table_gone', $table));
        }

        if (ChangeOperation::Create !== $request->operation) {
            $datasetId = $target->getDatasetId();
            if (null === $datasetId || null === $this->loadDataset($table, $datasetId)) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_yform_dataset_gone', (int) $datasetId, $table));
            }
        }

        if (ChangeOperation::Delete === $request->operation) {
            return;
        }

        $payload = $request->effectivePayload()->toArray();
        if ([] === $payload) {
            throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_no_fields'));
        }

        foreach (array_keys($payload) as $name) {
            $name = (string) $name;
            if ('id' === $name) {
                throw new ValidationException(rex_i18n::rawMsg('ai_platform_change_err_not_writable', 'id'));
            }
            if (!isset($fields[$name])) {
                throw new ValidationException(rex_i18n::rawMsg(
                    'ai_platform_change_err_yform_field_unknown',
                    $name,
                    $table,
                    implode(', ', array_keys($fields)),
                ));
            }
        }

        $this->assertReferencesResolve($request);
    }

    /**
     * Approval requires YForm's own EDIT authorisation for the table.
     *
     * YForm gates table access through the `yform_manager_table_edit` complex
     * permission, wrapped by rex_yform_manager_table::isGranted(). Using that
     * API rather than a hand-rolled check means an editor can only approve
     * writes into tables they could edit in the YForm manager anyway — and it
     * keeps working if YForm changes how it resolves those rights.
     */
    public function canApprove(rex_user $user, ChangeRequest $request): bool
    {
        $target = $this->requireTarget($request->target);

        if ($user->isAdmin()) {
            return true;
        }

        $table = $target->loadTable();
        if (null === $table) {
            return false;
        }

        return $table->isGranted(rex_yform_manager_table_authorization::EDIT, $user);
    }

    /**
     * Uses the real YForm field labels, and renders list fields as readable
     * lists instead of the stored comma string.
     */
    public function diffFields(ChangeRequest $request, ?array $currentValues = null): array
    {
        $target = $this->requireTarget($request->target);
        $fields = YformFieldRegistry::forTable($target->getTable());
        $before = $request->snapshotBefore ?? [];

        if (ChangeOperation::Delete === $request->operation) {
            $diff = [];
            foreach ($before as $name => $value) {
                $text = self::stringify($value);
                if (null === $text || '' === $text) {
                    continue;
                }
                $field = $fields[(string) $name] ?? null;
                $diff[] = new DiffField(
                    (string) $name,
                    null === $field ? (string) $name : YformFieldRegistry::label($field),
                    $text,
                    null,
                    DiffField::KIND_REMOVED,
                    null === $currentValues ? null : self::stringify($currentValues[$name] ?? null),
                );
            }

            return $diff;
        }

        $diff = [];
        foreach ($request->effectivePayload()->toArray() as $name => $after) {
            $name = (string) $name;
            $field = $fields[$name] ?? null;
            $label = null === $field ? $name : YformFieldRegistry::label($field);

            $beforeValue = array_key_exists($name, $before) ? self::stringify($before[$name]) : null;
            $afterValue = self::stringify($after);
            $currentValue = null === $currentValues ? null : self::stringify($currentValues[$name] ?? null);

            if (null !== $field && YformFieldRegistry::acceptsList($field)) {
                $beforeValue = self::formatList($beforeValue);
                $afterValue = self::formatList($afterValue);
                $currentValue = null === $currentValue ? null : self::formatList($currentValue);
            }

            $diff[] = DiffField::compare($name, $label, $beforeValue, $afterValue, $currentValue);
        }

        return $diff;
    }

    private static function formatList(?string $stored): ?string
    {
        if (null === $stored) {
            return null;
        }

        $values = YformFieldRegistry::decodeList($stored);

        return [] === $values ? '' : implode(', ', $values);
    }

    public function apply(ChangeRequest $request): ApplyResult
    {
        $target = $this->requireTarget($request->target);
        $table = $target->getTable();

        // Re-check the allow list here as well. validate() already did, but
        // apply() must not depend on having been called through it.
        if (!ChangeService::isYformTableAllowed($table)) {
            return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_yform_table_not_allowed', $table));
        }

        try {
            if (ChangeOperation::Delete === $request->operation) {
                $dataset = $this->loadDataset($table, (int) $target->getDatasetId());
                if (null === $dataset) {
                    return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_yform_dataset_gone', (int) $target->getDatasetId(), $table));
                }

                return $dataset->delete()
                    ? ApplyResult::ok(['dataset_id' => $target->getDatasetId()], [], [rex_i18n::rawMsg('ai_platform_change_yform_deleted')])
                    : ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_yform_delete_failed'));
            }

            $dataset = ChangeOperation::Create === $request->operation
                ? rex_yform_manager_dataset::create($table)
                : $this->loadDataset($table, (int) $target->getDatasetId());

            if (null === $dataset) {
                return ApplyResult::failed(rex_i18n::rawMsg('ai_platform_change_err_yform_dataset_gone', (int) $target->getDatasetId(), $table));
            }

            $fields = YformFieldRegistry::forTable($table);
            foreach ($request->effectivePayload()->toArray() as $name => $value) {
                $name = (string) $name;
                if ('id' === $name || !isset($fields[$name])) {
                    continue;
                }
                $dataset->setValue($name, $value);
            }

            // YForm's own validation — the same rules a human submission faces.
            if (!$dataset->isValid()) {
                return ApplyResult::failed($this->formatMessages($dataset->getMessages()));
            }

            if (!$dataset->save()) {
                return ApplyResult::failed($this->formatMessages($dataset->getMessages()));
            }
        } catch (Throwable $e) {
            return ApplyResult::failed($e->getMessage());
        }

        return ApplyResult::ok(
            ['dataset_id' => $dataset->getId(), 'table' => $table],
            [],
            [rex_i18n::rawMsg('ai_platform_change_yform_saved')],
        );
    }

    /**
     * @param array<mixed> $messages
     */
    private function formatMessages(array $messages): string
    {
        $flat = [];
        foreach ($messages as $key => $message) {
            if (is_array($message)) {
                foreach ($message as $entry) {
                    $flat[] = (string) $key . ': ' . (string) $entry;
                }
                continue;
            }
            $flat[] = (string) $message;
        }

        return [] === $flat
            ? rex_i18n::rawMsg('ai_platform_change_err_yform_invalid')
            : implode(' | ', $flat);
    }

    /**
     * Loads a dataset for writing, bypassing the instance pool.
     *
     * rex_yform_manager_dataset caches instances per (table, id), and save()
     * writes back everything in $this->data — the whole row as it was loaded.
     * Working from a cached instance would therefore not just read stale values,
     * it would write them: fields nobody proposed changing would be reset to
     * whatever they were when the instance was first loaded, silently undoing
     * somebody else's edit. Dropping the pooled instance first makes the write
     * start from the current row.
     */
    private function loadDataset(string $table, int $id): ?rex_yform_manager_dataset
    {
        if (!YformFieldRegistry::isAvailable() || $id < 1) {
            return null;
        }

        rex_yform_manager_dataset::clearInstance([$table, $id]);

        return rex_yform_manager_dataset::get($id, $table);
    }

    private function requireTarget(TargetInterface $target): YformTarget
    {
        if (!$target instanceof YformTarget) {
            throw new ValidationException(sprintf('YformHandler needs a YformTarget, got %s.', $target::class));
        }

        return $target;
    }
}
