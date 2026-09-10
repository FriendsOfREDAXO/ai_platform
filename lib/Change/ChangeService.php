<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use FriendsOfRedaxo\AiPlatform\Change\Upload\PendingUploadStore;
use rex;
use rex_config;
use rex_content_service;
use rex_extension;
use rex_extension_point;
use rex_i18n;
use rex_sql;
use rex_user;
use RuntimeException;
use Throwable;

use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function strlen;

/**
 * The public API of the change request feature.
 *
 * Every way of submitting a proposal goes through propose() — the PHP API for
 * other addons, the agent tool, the backend form, and anything a project
 * builds on top (an api addon route, an own rex_api_function, an MCP tool).
 * Authentication belongs to those adapters; whoever can call this class is
 * already trusted to ask. What this class guarantees is that asking does not
 * write: a proposal only reaches the database as a proposal, and only an
 * approval by a backend user with the right permissions turns it into a write.
 */
final class ChangeService
{
    private static ?self $instance = null;

    private readonly ChangeRequestStore $store;

    /**
     * Article/language pairs whose content cache needs regenerating, collected
     * across a batch so a changeset touching fifty slices in one article
     * regenerates it once instead of fifty times.
     *
     * @var array<string, array{article_id: int, clang_id: int}>
     */
    private array $pendingCacheRebuilds = [];

    private function __construct(?ChangeRequestStore $store = null)
    {
        $this->store = $store ?? new ChangeRequestStore();
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getStore(): ChangeRequestStore
    {
        return $this->store;
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    /**
     * Current state of a target, with the fingerprint used for stale
     * detection. A caller needs this to formulate a sensible proposal in the
     * first place; it is not a prerequisite for propose(), which reads the
     * state itself.
     */
    public function read(TargetInterface $target): CurrentState
    {
        $handler = HandlerRegistry::forTarget($target);
        $values = $handler->readCurrent($target);

        return new CurrentState(
            $values,
            $this->fingerprint($handler, $values),
            $target->describe(),
        );
    }

    // -----------------------------------------------------------------
    // Proposing
    // -----------------------------------------------------------------

    /**
     * Records a proposed change. Writes nothing to the target.
     *
     * @param string|null $changesetKey Groups proposals into one package. The
     *        submitter picks the name; it is scoped to the source, so two
     *        sources cannot land in each other's package.
     *
     * @return int the new change request id
     *
     * @throws ValidationException when the proposal cannot be written as described
     * @throws TargetGoneException when an update or delete points at nothing
     * @throws RuntimeException when the feature is off or a limit is hit
     */
    public function propose(
        TargetInterface $target,
        PayloadInterface $payload,
        string $reason,
        Source $source,
        ?string $changesetKey = null,
        string $changesetTitle = '',
    ): int {
        $this->assertEnabled();

        $handler = HandlerRegistry::forTarget($target);
        $operation = $target->operation();

        if (!in_array($operation, $handler->supportedOperations(), true)) {
            throw new ValidationException(sprintf(
                'Handler "%s" does not support the %s operation.',
                $handler->getType(),
                $operation->value,
            ));
        }

        if ($payload->isEmpty() && ChangeOperation::Delete !== $operation) {
            throw new ValidationException('A change request must set at least one field.');
        }


        // Read the current state ourselves rather than trusting a caller to
        // hand it in. This closes the gap that actually matters: between the
        // proposal being recorded and a reviewer approving it, hours or days
        // later, someone may edit the target by hand. The fingerprint taken
        // here is what detects that at approval time.
        $snapshot = $handler->readCurrent($target);

        if ($operation->needsSnapshot() && null === $snapshot) {
            throw new TargetGoneException(sprintf('Cannot change %s — it does not exist.', $target->describe()));
        }

        // The surroundings, separately. For a create this is the only thing
        // there is to compare later — a create has no target of its own yet.
        $context = $handler->readContext($target);

        $request = new ChangeRequest(
            id: null,
            type: $handler->getType(),
            operation: $operation,
            target: $target,
            payload: $payload,
            payloadEdited: null,
            snapshotBefore: $snapshot,
            baseHash: $this->fingerprint($handler, $snapshot),
            contextSnapshot: $context,
            contextHash: null === $context ? null : $this->fingerprint($handler, $context),
            reason: trim($reason),
            status: ChangeStatus::Pending,
            source: $source,
            changesetId: $this->resolveChangeset($source, $changesetKey, $changesetTitle),
            targetLabel: $target->describe(),
        );

        // Fail fast on things we can already tell are wrong, so the caller
        // learns about it now instead of a reviewer learning about it later.
        $handler->validate($request);

        $id = $this->store->insert($request);

        // Staged files now belong to this request. Until this runs the upload
        // looks abandoned — which it was — and the cronjob would eventually sweep
        // it. Generic on purpose: the core asks the payload what it carries and
        // does not know that media exist.
        $attachments = $payload->attachments();
        if ([] !== $attachments) {
            (new PendingUploadStore())->attach($attachments, $id);
        }

        // An earlier open proposal for the same target is now outdated. Left
        // alone, a reviewer could approve both and the older one would undo
        // the newer.
        //
        // Creates are exempt, and that exemption is load-bearing. A create
        // target names a *place*, not a thing: every new category under parent
        // 40 serialises to the same `{parent_id:40, clang_id:1}`. Superseding on
        // that basis means proposing six subcategories leaves exactly one alive
        // — the others vanish, silently, and the agent has no way to tell.
        // Two proposals to create something in one place are two wishes, and
        // neither undoes the other; two proposals to change one field are a
        // correction, and the older one does.
        if (ChangeOperation::Create !== $operation) {
            $this->store->markSuperseded(
                $this->store->findOpenIdsForTarget($handler->getType(), $target, $id),
            );
        }

        rex_extension::registerPoint(new rex_extension_point('AI_PLATFORM_CHANGE_PROPOSED', null, [
            'request_id' => $id,
            'type' => $handler->getType(),
            'operation' => $operation->value,
            'source_key' => $source->key,
        ]));

        return $id;
    }

    /**
     * Convenience wrapper for deletions, which carry no values.
     */
    public function proposeDelete(
        TargetInterface $target,
        string $reason,
        Source $source,
        ?string $changesetKey = null,
        string $changesetTitle = '',
    ): int {
        if (ChangeOperation::Delete !== $target->operation()) {
            throw new ValidationException('proposeDelete() needs a target built with forDeletion().');
        }

        $handler = HandlerRegistry::forTarget($target);
        /** @var class-string<PayloadInterface> $payloadClass */
        $payloadClass = $handler->payloadClass();

        return $this->propose($target, $payloadClass::fromArray([]), $reason, $source, $changesetKey, $changesetTitle);
    }

    // -----------------------------------------------------------------
    // Reviewing
    // -----------------------------------------------------------------

    public function getRequest(int $id): ?ChangeRequest
    {
        return $this->store->findLoadable($id);
    }

    /**
     * Everything that has moved under a change request since it was proposed.
     *
     * Four independent findings — target deleted, target edited, dangling
     * references, changed surroundings — because they need different answers.
     * See TargetInspection for what each one means and which of them can be
     * overridden.
     */
    public function inspectTarget(ChangeRequest $request): TargetInspection
    {
        $handler = HandlerRegistry::require($request->type);

        // References first: they matter whatever the operation is, and a
        // dangling one makes everything else moot.
        try {
            $brokenReferences = $handler->checkReferences($request);
        } catch (Throwable $e) {
            // A handler that cannot even check must not take the whole inbox
            // down; report it as a finding instead.
            $brokenReferences = [$e->getMessage()];
        }

        $contextChanges = $this->detectContextChanges($handler, $request);

        if (!$request->operation->needsSnapshot()) {
            // A create has no target to compare — context and references are
            // all there is.
            return new TargetInspection(
                contextChanged: [] !== $contextChanges,
                brokenReferences: $brokenReferences,
                contextChanges: $contextChanges,
            );
        }

        $current = $handler->readCurrent($request->target);
        if (null === $current) {
            return new TargetInspection(
                gone: true,
                brokenReferences: $brokenReferences,
                contextChanges: $contextChanges,
                contextChanged: [] !== $contextChanges,
            );
        }

        $changed = $this->fingerprint($handler, $current) !== $request->baseHash;

        return new TargetInspection(
            changed: $changed,
            contextChanged: [] !== $contextChanges,
            currentValues: $current,
            changedFields: $changed ? $this->diffFieldNames($request, $current) : [],
            brokenReferences: $brokenReferences,
            contextChanges: $contextChanges,
        );
    }

    /**
     * Names the fields that moved, so the reviewer is told what changed rather
     * than just that something did.
     *
     * @param array<string, scalar|null> $current
     * @return list<string>
     */
    private function diffFieldNames(ChangeRequest $request, array $current): array
    {
        $before = $request->snapshotBefore ?? [];
        $names = [];

        foreach ($current as $key => $value) {
            $key = (string) $key;
            if (!array_key_exists($key, $before)) {
                continue;
            }
            if ((string) $before[$key] !== (string) $value) {
                $names[] = $key;
            }
        }

        return $names;
    }

    /**
     * Compares the recorded surroundings with the present ones.
     *
     * @return list<string>
     */
    private function detectContextChanges(HandlerInterface $handler, ChangeRequest $request): array
    {
        if (null === $request->contextHash) {
            return [];
        }

        try {
            $context = $handler->readContext($request->target);
        } catch (Throwable) {
            return [];
        }

        if (null === $context) {
            return [];
        }

        if ($this->fingerprint($handler, $context) === $request->contextHash) {
            return [];
        }

        // Report the concrete difference where the snapshot allows it. Counting
        // keys is generic enough to work for every handler and specific enough
        // to be worth reading.
        $before = $request->contextSnapshot ?? [];
        $changes = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;
            $previous = $before[$key] ?? null;
            if ((string) $previous === (string) $value) {
                continue;
            }
            $changes[] = rex_i18n::rawMsg(
                'ai_platform_change_context_field_changed',
                $key,
                null === $previous ? '—' : (string) $previous,
                null === $value ? '—' : (string) $value,
            );
        }

        return [] === $changes
            ? [rex_i18n::rawMsg('ai_platform_change_context_changed_generic')]
            : $changes;
    }

    /**
     * Approves and applies one request.
     *
     * @param bool $force Override a stale target deliberately. Only ever set
     *        from an explicit reviewer action, never as a default.
     *
     * @throws RuntimeException when the user may not approve this request
     * @throws StaleTargetException when the target moved and $force is false
     * @throws TargetGoneException when the target disappeared
     * @throws ValidationException when the request is no longer applicable
     */
    public function approve(int $id, rex_user $user, bool $force = false, ?string $note = null): ApplyResult
    {
        $this->assertEnabled();

        $request = $this->store->findLoadable($id);
        if (null === $request) {
            throw new RuntimeException(sprintf('Change request %d not found, or its handler is not installed.', $id));
        }

        if (!$request->isOpen()) {
            throw new RuntimeException(sprintf(
                'Change request %d is already %s.',
                $id,
                $request->status->value,
            ));
        }

        $handler = HandlerRegistry::require($request->type);

        // Two gates, both required: the blanket reviewer permission, and the
        // per-target check. Without the second, approving would be a way to
        // write past the reviewer's own REDAXO permissions.
        if (!self::mayApprove($user)) {
            throw new RuntimeException(rex_i18n::rawMsg('ai_platform_change_no_approve_permission'));
        }

        if (!$handler->canApprove($user, $request)) {
            throw new RuntimeException(rex_i18n::rawMsg('ai_platform_change_no_approve_permission'));
        }

        return $this->runApproval($request, $handler, $force, $note, $user, Source::CHANNEL_BACKEND);
    }

    /**
     * Approves without a REDAXO user, on the authority of an API token.
     *
     * The two gates a human approval passes are not available here: an API token
     * has no `rex_user`, so there is no blanket permission to check and no
     * per-target `canApprove()`. What bounds this instead is the scope on the
     * token — granting `ai_platform/changes/approve` is the deliberate act — plus
     * the one invariant in {@see ApiApproval}: a caller may only decide its own
     * proposals. See that class for why there is nothing else to configure.
     *
     * `force` is deliberately not a parameter. A stale target or a broken
     * reference is exactly the case that needs someone to look, and the caller
     * cannot know what moved.
     *
     * @throws RuntimeException when the policy refuses, or the request is not open
     */
    public function approveViaApi(int $id, Source $source, ?string $note = null): ApplyResult
    {
        $this->assertEnabled();

        $request = $this->store->findLoadable($id);
        if (null === $request) {
            throw new RuntimeException(sprintf('Change request %d not found, or its handler is not installed.', $id));
        }

        if (!$request->isOpen()) {
            throw new RuntimeException(sprintf(
                'Change request %d is already %s.',
                $id,
                $request->status->value,
            ));
        }

        if (null !== $refusal = ApiApproval::refusalReason($request, $source)) {
            throw new RuntimeException($refusal);
        }

        return $this->runApproval(
            $request,
            HandlerRegistry::require($request->type),
            false,
            $note,
            null,
            Source::CHANNEL_API,
        );
    }

    /**
     * Everything an approval does once it is allowed to happen.
     *
     * Shared by the backend and the API path so the two cannot drift: situation
     * check, re-validation, the veto extension point, the write, reading the
     * target back, and recording the outcome. Only the authorisation differs,
     * and that is settled before this is called.
     *
     * @param rex_user|null $user Null for an API approval — there is no user then,
     *                            and pretending otherwise would put a wrong id in
     *                            `reviewed_by`
     * @param string $via One of the Source::CHANNEL_* values, recorded in
     *                    `reviewed_via` so an editor can see afterwards how the
     *                    decision came about
     */
    private function runApproval(
        ChangeRequest $request,
        HandlerInterface $handler,
        bool $force,
        ?string $note,
        ?rex_user $user,
        string $via,
    ): ApplyResult {
        $id = (int) $request->id;
        $userId = $user?->getId();

        $inspection = $this->inspectTarget($request);

        if ($inspection->gone) {
            $this->store->updateStatus($id, ChangeStatus::Expired, $userId, $note, $via);
            throw new TargetGoneException(sprintf('%s no longer exists.', $request->label()));
        }

        // A dangling reference is not overridable: applying would write data
        // that points at nothing, and no amount of reviewer intent fixes that.
        if ($inspection->hasBrokenReferences()) {
            throw new ValidationException(implode(' ', $inspection->brokenReferences));
        }

        if ($inspection->changed && !$force && $this->blocksOnStale()) {
            throw new StaleTargetException(
                $request->baseHash,
                $this->fingerprint($handler, $inspection->currentValues),
            );
        }

        // Validate again: modules get deleted, metainfo fields removed, YForm
        // columns dropped between proposal and approval.
        $handler->validate($request);

        // `user` is null on an API approval. Consumers that assumed a user must
        // handle that; `via` tells them which case they are in.
        $veto = rex_extension::registerPoint(new rex_extension_point('AI_PLATFORM_CHANGE_BEFORE_APPLY', null, [
            'request' => $request,
            'user' => $user,
            'via' => $via,
        ]));
        if (is_string($veto) && '' !== $veto) {
            throw new ValidationException($veto);
        }

        try {
            $result = $handler->apply($request);
        } catch (Throwable $e) {
            $this->store->recordFailure($id, $e->getMessage());
            $this->refreshChangesetStatus($request->changesetId);

            return ApplyResult::failed($e->getMessage());
        }

        if (!$result->success) {
            $this->store->recordFailure($id, $result->error ?? 'unknown error');
            $this->refreshChangesetStatus($request->changesetId);

            return $result;
        }

        // Read the target back so the record shows what was actually stored,
        // not just what was asked for. Some REDAXO writes normalise their input
        // — see ApplyResult::$valuesAfter for the priority case.
        $result = $result->withValuesAfter($this->readValuesAfter($handler, $request, $result));

        $this->store->updateStatus($id, ChangeStatus::Approved, $userId, $note, $via);
        $this->store->recordApplied($id, $result);
        $this->collectCacheRebuilds($result);
        $this->refreshChangesetStatus($request->changesetId);

        rex_extension::registerPoint(new rex_extension_point('AI_PLATFORM_CHANGE_APPLIED', null, [
            'request_id' => $id,
            'type' => $request->type,
            'result' => $result,
            'user' => $user,
            'via' => $via,
        ]));

        return $result;
    }

    /**
     * Takes back a proposal on the authority of whoever submitted it.
     *
     * The gap this closes: an agent that noticed its own mistake had no way to
     * clear it. It could only leave the misfire in an editor's inbox and hope
     * someone bothered to reject it — which turns an agent's error into a
     * person's chore, exactly the wrong way round.
     *
     * Nothing is deleted. The row moves to {@see ChangeStatus::Withdrawn}, which
     * is kept distinct from Rejected on purpose: "an editor said no" and "the
     * submitter took it back" are different facts, and only one of them says
     * anything about the proposal's quality.
     *
     * Three limits, none configurable:
     *
     * 1. **Only your own.** The source key has to match. Withdrawing someone
     *    else's proposal would be deciding it, under a friendlier name.
     * 2. **Only while pending.** Once an editor has approved it, taking it back
     *    is no longer the submitter's call — and by then it is usually written.
     * 3. **No write happens.** This is the one operation in the whole feature
     *    that cannot touch content, which is why it needs no policy.
     *
     * @param string|null $reason Recorded on the row. An agent that says why it
     *                            withdrew leaves something useful behind; the
     *                            alternative is a silent status change nobody
     *                            can interpret later.
     * @throws RuntimeException when the request is unknown, not pending, or
     *                          belongs to a different source
     */
    public function withdraw(int $id, Source $source, ?string $reason = null): void
    {
        $this->assertEnabled();

        $request = $this->store->find($id);
        if (null === $request) {
            throw new RuntimeException(sprintf('Change request %d not found.', $id));
        }

        if ($request->source->key !== $source->key) {
            throw new RuntimeException('A caller may only withdraw change requests it submitted itself.');
        }

        if (ChangeStatus::Pending !== $request->status) {
            throw new RuntimeException(sprintf(
                'Change request %d is %s and can no longer be withdrawn. Only a pending request can be taken back.',
                $id,
                $request->status->value,
            ));
        }

        $this->store->updateStatus(
            $id,
            ChangeStatus::Withdrawn,
            null,
            $reason,
            $source->channel,
        );
        $this->refreshChangesetStatus($request->changesetId);

        rex_extension::registerPoint(new rex_extension_point('AI_PLATFORM_CHANGE_WITHDRAWN', null, [
            'request_id' => $id,
            'type' => $request->type,
            'source_key' => $source->key,
            'reason' => $reason,
        ]));
    }

    public function reject(int $id, rex_user $user, string $note = ''): void
    {
        $request = $this->store->find($id);
        if (null === $request) {
            throw new RuntimeException(sprintf('Change request %d not found.', $id));
        }
        if (!$request->isOpen()) {
            throw new RuntimeException(sprintf('Change request %d is already %s.', $id, $request->status->value));
        }

        // Rejecting needs the same permission as approving: both are decisions
        // about content the user must be responsible for.
        if (!self::mayApprove($user)) {
            throw new RuntimeException(rex_i18n::rawMsg('ai_platform_change_no_approve_permission'));
        }

        $handler = HandlerRegistry::get($request->type);
        if (null !== $handler && !$handler->canApprove($user, $request)) {
            throw new RuntimeException(rex_i18n::rawMsg('ai_platform_change_no_approve_permission'));
        }

        $this->store->updateStatus($id, ChangeStatus::Rejected, $user->getId(), $note);
        $this->refreshChangesetStatus($request->changesetId);
    }

    /**
     * Applies several requests, one after another.
     *
     * @param list<int> $ids
     */
    public function approveMany(array $ids, rex_user $user, bool $force = false): BatchResult
    {
        $result = new BatchResult();

        foreach ($ids as $id) {
            $id = (int) $id;

            try {
                $applied = $this->approve($id, $user, $force);
                if ($applied->success) {
                    $result->recordApplied($id);
                } else {
                    $result->recordFailed($id, $applied->error ?? 'unknown error');
                }
            } catch (StaleTargetException $e) {
                $result->recordStale($id, $e->getMessage());
            } catch (Throwable $e) {
                $result->recordFailed($id, $e->getMessage());
            }
        }

        $this->flushCacheRebuilds();

        return $result;
    }

    /**
     * @param list<int> $ids
     */
    public function rejectMany(array $ids, rex_user $user, string $note = ''): BatchResult
    {
        $result = new BatchResult();

        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                $this->reject($id, $user, $note);
                $result->recordApplied($id);
            } catch (Throwable $e) {
                $result->recordSkipped($id, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Approves every open request in a package.
     */
    /**
     * Approves everything still open in one changeset, **in submission order**.
     *
     * The order is not incidental and callers rely on it. A media create followed
     * by a slice that shows the new image only works if the image is written
     * first: `SliceHandler::checkReferences()` resolves `media1` slots against
     * `rex_media::get()`, so the slice is refused until the file exists. Submit
     * in dependency order and the changeset applies in that same order —
     * `findByChangeset()` sorts by id, which is submission order, and this method
     * preserves it.
     *
     * It is not a transaction and cannot be: the REDAXO services write file
     * caches and fire extension points that send mail or clear rewrite caches,
     * none of which a rollback would undo. If the image fails, the slice fails
     * after it with a reference error rather than writing a broken slot — which is
     * the outcome to want.
     */
    public function approveChangeset(int $changesetId, rex_user $user, bool $force = false): BatchResult
    {
        $ids = [];
        foreach ($this->store->findByChangeset($changesetId) as $request) {
            if ($request->isOpen() && null !== $request->id) {
                $ids[] = $request->id;
            }
        }

        return $this->approveMany($ids, $user, $force);
    }

    /**
     * Stores a reviewer's correction. The original proposal is kept — it is
     * the only record of what the source actually suggested, and the basis for
     * judging how useful a given agent is.
     */
    public function editPayload(int $id, PayloadInterface $payload, rex_user $user): void
    {
        $request = $this->store->findLoadable($id);
        if (null === $request) {
            throw new RuntimeException(sprintf('Change request %d not found.', $id));
        }
        if (!$request->isOpen()) {
            throw new RuntimeException('Only open change requests can be edited.');
        }

        if (!self::mayApprove($user)) {
            throw new RuntimeException(rex_i18n::rawMsg('ai_platform_change_no_approve_permission'));
        }

        $handler = HandlerRegistry::require($request->type);
        if (!$handler->canApprove($user, $request)) {
            throw new RuntimeException(rex_i18n::rawMsg('ai_platform_change_no_approve_permission'));
        }

        $handler->validate($request->withPayloadEdited($payload));
        $this->store->saveEditedPayload($id, $payload);
    }

    // -----------------------------------------------------------------
    // Changesets
    // -----------------------------------------------------------------

    /**
     * Opens a package, or returns the existing one for this source and key.
     *
     * @return string the changeset token
     */
    public function openChangeset(Source $source, ?string $clientKey = null, string $title = '', string $description = ''): string
    {
        if (null !== $clientKey && '' !== $clientKey) {
            $existing = $this->store->findChangesetByClientKey($source->key, $clientKey);
            if (null !== $existing) {
                return $existing->token;
            }
        }

        $changeset = new Changeset(
            id: null,
            token: bin2hex(random_bytes(16)),
            clientKey: $clientKey,
            title: $title,
            description: $description,
            status: Changeset::STATUS_OPEN,
            source: $source,
        );

        $this->store->insertChangeset($changeset);

        return $changeset->token;
    }

    public function getChangesetByToken(string $token): ?Changeset
    {
        return $this->store->findChangesetByToken($token);
    }

    /**
     * Recomputes a package's status from its members, so it is never set by
     * hand and cannot drift.
     */
    public function refreshChangesetStatus(?int $changesetId): void
    {
        if (null === $changesetId) {
            return;
        }

        $counts = $this->store->changesetCounts($changesetId);
        if (0 === $counts['total']) {
            return;
        }

        $status = match (true) {
            $counts['open'] > 0 => Changeset::STATUS_OPEN,
            $counts['applied'] === $counts['total'] => Changeset::STATUS_APPLIED,
            0 === $counts['applied'] => Changeset::STATUS_REJECTED,
            default => Changeset::STATUS_PARTIAL,
        };

        $this->store->updateChangesetStatus($changesetId, $status);
    }

    private function resolveChangeset(Source $source, ?string $changesetKey, string $title): ?int
    {
        if (null === $changesetKey || '' === trim($changesetKey)) {
            return null;
        }

        $token = $this->openChangeset($source, trim($changesetKey), $title);

        return $this->store->findChangesetByToken($token)?->id;
    }

    /**
     * Re-reads the target after a write.
     *
     * Only meaningful for updates: a create has no stable target to read (its
     * id is in the ApplyResult, not in the target), and after a delete there is
     * nothing left.
     *
     * @return array<string, scalar|null>|null
     */
    private function readValuesAfter(HandlerInterface $handler, ChangeRequest $request, ApplyResult $result): ?array
    {
        if (ChangeOperation::Update !== $request->operation) {
            return null;
        }

        try {
            return $handler->readCurrent($request->target);
        } catch (Throwable) {
            // Losing the verification read must not turn a successful write
            // into a failure.
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Content cache
    // -----------------------------------------------------------------

    private function collectCacheRebuilds(ApplyResult $result): void
    {
        foreach ($result->touchedArticles as $touched) {
            $key = $touched['article_id'] . '/' . $touched['clang_id'];
            $this->pendingCacheRebuilds[$key] = $touched;
        }
    }

    /**
     * Regenerates the content of every article touched by the batch, once per
     * article and language.
     */
    public function flushCacheRebuilds(): void
    {
        if ([] === $this->pendingCacheRebuilds) {
            return;
        }

        $rebuilds = $this->pendingCacheRebuilds;
        $this->pendingCacheRebuilds = [];

        foreach ($rebuilds as $touched) {
            try {
                rex_content_service::generateArticleContent($touched['article_id'], $touched['clang_id']);
            } catch (Throwable) {
                // A cache that cannot be regenerated is a nuisance, not a
                // reason to lose the record of a successful write. REDAXO
                // rebuilds it on the next request anyway.
                continue;
            }
        }
    }

    // -----------------------------------------------------------------
    // Configuration and limits
    // -----------------------------------------------------------------

    private function fingerprint(HandlerInterface $handler, ?array $values): string
    {
        if ($handler instanceof AbstractHandler) {
            return $handler->fingerprint($values);
        }

        // A handler not derived from AbstractHandler still has to be
        // fingerprintable, so fall back to the same normalisation.
        if (null === $values) {
            return 'absent';
        }
        $normalised = array_map(static fn($v) => null === $v ? "\0null" : (string) $v, $values);
        ksort($normalised);

        return hash('sha256', json_encode($normalised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function isEnabled(): bool
    {
        return (bool) rex_config::get('ai_platform', 'changes_enabled', true);
    }

    /**
     * Whether the user may decide on requests at all.
     *
     * Separate from the per-target check in `canApprove()`: this is the blanket
     * "is this person a reviewer" question, the handler answers "may they touch
     * *this* target". Both have to pass.
     */
    public static function mayApprove(?rex_user $user = null): bool
    {
        $user ??= rex::getUser();
        if (null === $user) {
            return false;
        }

        return $user->isAdmin() || $user->hasPerm('ai_changes[approve]');
    }

    /**
     * Adjusts the backend menu for change requests. Called from PAGES_PREPARED.
     *
     * Takes and returns the page array rather than reaching for
     * rex_be_controller::getPages()/setPages(). That matters: backend.php does
     *
     *     $pages = registerPoint(new rex_extension_point('PAGES_PREPARED', getPages()));
     *     setPages($pages);
     *
     * so a handler that writes through setPages() has its work overwritten one
     * line later by the unmodified subject. Only the return value survives.
     *
     * Removes the page outright when the feature is off rather than hiding it: a
     * hidden page is still reachable by URL, and a switched-off feature should
     * not leave a working settings form behind.
     *
     * @param array<string, \rex_be_page> $pages
     * @return array<string, \rex_be_page>
     */
    public static function preparePages(array $pages): array
    {
        if (!(bool) rex_config::get('ai_platform', 'changes_enabled', true)) {
            unset($pages['ai_changes']);

            return $pages;
        }

        $user = rex::getUser();
        if (null === $user) {
            return $pages;
        }

        $page = $pages['ai_changes'] ?? null;
        if (!$page instanceof \rex_be_page) {
            return $pages;
        }

        // `ai_changes[approve]` implies access to the section. Without this it is
        // decorative: rex_be_page::checkPermission() ANDs the page's own
        // requirement with every parent's, so a user granted only the option
        // would be turned away at the top-level page and never reach the inbox
        // they are supposed to decide in. The option lives in a different block
        // of the role form than the general permission, which makes the missing
        // second tick invisible rather than merely tedious.
        if (!$user->isAdmin()
            && !$user->hasPerm('ai_changes[]')
            && self::mayApprove($user)
        ) {
            $page->setRequiredPermissions([]);
        }

        if (!$user->isAdmin() && !$page->checkPermission($user)) {
            return $pages;
        }

        try {
            $open = (new ChangeRequestStore())->countOpen();
        } catch (Throwable) {
            // Tables missing because the addon was not reinstalled after an
            // update — the menu still has to render.
            return $pages;
        }

        if ($open < 1) {
            return $pages;
        }

        // The badge is what makes anyone remember the inbox exists — but it must
        // not go into the title. `rex_be_controller::getPageTitle()` builds the
        // `<title>` element from `$page->getTitle()` verbatim, so HTML appended
        // there ends up spelled out in the browser tab as
        // `KI Änderungen <span class="badge">1</span> · REDAXO`.
        //
        // That was invisible while the inbox was a subpage: the *active* page was
        // then `ai_changes/list`, whose own title carried no badge. Collapsing the
        // section to a single page made the badged page the active one, and the
        // markup surfaced. A data attribute plus a CSS `::after` keeps the count
        // in the menu and out of the document title.
        $page->setLinkAttr('data-ai-changes-open', (string) $open);

        return $pages;
    }

    private function assertEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw new RuntimeException(rex_i18n::rawMsg('ai_platform_change_disabled'));
        }
    }

    public function blocksOnStale(): bool
    {
        return 'warn' !== rex_config::get('ai_platform', 'changes_stale_policy', 'block');
    }


    /**
     * Whether a module may receive proposed slices.
     *
     * This replaced a hand-maintained allow list, and the reason is one specific
     * module: a REDAXO module whose output contains `REX_VALUE[... output=php]`
     * executes the slice value as PHP. A proposal for such a module is not
     * content, it is code — and once an API token can approve its own proposals,
     * it is code that runs without anyone reading it. Nearly every installation
     * has one of these lying around; this one ships module 19 "php - Code".
     *
     * An allow list is the wrong instrument for that. It has to be maintained,
     * it defaults to permissive because that is what makes the addon usable, and
     * the one entry that matters is the one an admin forgets. Reading the module
     * output instead needs no configuration and cannot be forgotten.
     *
     * Refused regardless of who asks — this is not a permission but a property
     * of the module. A project that wants such a module writable anyway replaces
     * the slice handler through `AI_PLATFORM_CHANGE_HANDLERS`, which is a
     * deliberate act in code rather than a checkbox.
     */
    public static function isModuleAllowed(int $moduleId): bool
    {
        return !self::moduleExecutesPhp($moduleId);
    }

    /**
     * Whether this module runs its slice values as PHP.
     *
     * `output=php` is REDAXO's own marker for "evaluate this value", so matching
     * it is reading the module's intent rather than guessing. `eval(` is checked
     * too, for modules that build the same thing by hand.
     */
    public static function moduleExecutesPhp(int $moduleId): bool
    {
        if ($moduleId < 1) {
            return false;
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT output FROM ' . rex::getTable('module') . ' WHERE id = :id',
            [':id' => $moduleId],
        );

        $output = (string) ($rows[0]['output'] ?? '');
        if ('' === $output) {
            return false;
        }

        return 1 === preg_match('/REX_VALUE\[[^]]*output\s*=\s*[\'"]?php/i', $output)
            || str_contains($output, 'eval(');
    }

    /**
     * Whether every YForm table is writable.
     *
     * Defaults to **off**, unlike the module switch. A YForm target can name any
     * table in the database, so opening all of them has to be a deliberate act —
     * otherwise a fresh install would accept a proposal against rex_ycom_user.
     */
    public static function allowsAllYformTables(): bool
    {
        return (bool) rex_config::get('ai_platform', 'changes_allow_all_yform_tables', false);
    }

    /**
     * YForm tables that may be written to. Only consulted when
     * {@see allowsAllYformTables()} is off.
     *
     * @return list<string>
     */
    public static function allowedYformTables(): array
    {
        $raw = (string) rex_config::get('ai_platform', 'changes_allowed_yform_tables', '');
        if ('' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }

        // Also accept a plain comma/newline separated list, so the setting can
        // be edited by hand without knowing it is JSON.
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
    }

    public static function isYformTableAllowed(string $table): bool
    {
        if (self::allowsAllYformTables()) {
            return true;
        }

        return in_array($table, self::allowedYformTables(), true);
    }

    /**
     * Tables offered in the manual form: the explicit list, or every YForm table
     * when everything is allowed.
     *
     * @return list<string>
     */
    public static function selectableYformTables(): array
    {
        if (!self::allowsAllYformTables()) {
            return self::allowedYformTables();
        }

        if (!class_exists('rex_yform_manager_table')) {
            return [];
        }

        $tables = [];
        foreach (\rex_yform_manager_table::getAll() as $table) {
            $tables[] = $table->getTableName();
        }

        return $tables;
    }

    /**
     * @return list<int>
     */
    private static function decodeIdList(string $raw): array
    {
        if ('' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_map('intval', array_filter($decoded, 'is_numeric')));
        }

        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_map('intval', array_filter($parts, 'is_numeric')));
    }

    /**
     * Test seam.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
