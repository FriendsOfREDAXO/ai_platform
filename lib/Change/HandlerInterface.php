<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use FriendsOfRedaxo\AiPlatform\Change\Diff\DiffField;
use rex_user;

/**
 * Everything the addon needs to know about one kind of change.
 *
 * The core deliberately understands nothing about slices, metainfo or YForm
 * datasets — a handler is the only place that does. Adding a new change type
 * means writing a handler plus its target/payload pair and registering it via
 * the AI_PLATFORM_CHANGE_HANDLERS extension point; no core file changes.
 *
 * Handlers are stateless: they are constructed once per request and receive
 * everything they need as arguments.
 */
interface HandlerInterface
{
    /**
     * Type key, matching the target class's changeType(), e.g. `slice`.
     */
    public function getType(): string;

    /**
     * Translated label for lists and filters.
     */
    public function getLabel(): string;

    /**
     * Fully qualified class name of this type's target.
     *
     * @return class-string<TargetInterface>
     */
    public function targetClass(): string;

    /**
     * Fully qualified class name of this type's payload.
     *
     * @return class-string<PayloadInterface>
     */
    public function payloadClass(): string;

    /**
     * @return list<ChangeOperation>
     */
    public function supportedOperations(): array;

    /**
     * Reads the target's current values, or null when it does not exist.
     *
     * The returned array is what gets fingerprinted and stored as
     * snapshot_before, so it must contain everything a payload could touch —
     * otherwise a change to an unlisted field would go unnoticed by stale
     * detection.
     *
     * @return array<string, scalar|null>|null
     */
    public function readCurrent(TargetInterface $target): ?array;

    /**
     * Reads the target's surroundings — what the proposal assumed about its
     * environment without addressing it directly.
     *
     * For a new slice that is the set of slices already in the target ctype; for
     * a new article the siblings in its category. Return null when a type has no
     * meaningful context.
     *
     * This is what makes create operations checkable at all: they have no target
     * to fingerprint, so without a context snapshot a week-old "insert at
     * position 1" proposal would land in front of content nobody had written
     * when it was made, and nothing would notice.
     *
     * @return array<string, scalar|null>|null
     */
    public function readContext(TargetInterface $target): ?array;

    /**
     * Lists references in the payload that no longer resolve.
     *
     * A media file, link target, module, template or related dataset can vanish
     * between proposal and approval. Applying then writes a dangling reference,
     * so these are reported as human-readable strings and block approval — there
     * is no "apply anyway" that yields correct data.
     *
     * Returns an empty list when everything resolves.
     *
     * @return list<string>
     */
    public function checkReferences(ChangeRequest $request): array;

    /**
     * Checks whether the request can be written as described.
     *
     * Called twice: once when proposing, for immediate feedback, and again
     * immediately before applying, because modules get deleted, metainfo
     * fields get removed and YForm columns get dropped in the meantime.
     *
     * Implementations must include the reference check, so a caller cannot end
     * up with a validated request that still points at something gone.
     *
     * @throws ValidationException
     */
    public function validate(ChangeRequest $request): void;

    /**
     * Whether this backend user may approve this request.
     *
     * This is the escalation guard, not a convenience check: without it the
     * feature becomes a way to write past REDAXO's permissions — an agent
     * proposes a change to a category the reviewer has no rights for, and
     * approval writes it anyway. Implementations must run the same checks the
     * corresponding backend page would.
     */
    public function canApprove(rex_user $user, ChangeRequest $request): bool;

    /**
     * Field-by-field description of what would change, for the reviewer.
     *
     * @param array<string, scalar|null>|null $currentValues Present state, when it
     *        differs from the snapshot — enables the three-column conflict view.
     * @return list<DiffField>
     */
    public function diffFields(ChangeRequest $request, ?array $currentValues = null): array;

    /**
     * Performs the write. Must not catch its own errors — the service records
     * the failure and keeps the rest of a changeset going.
     */
    public function apply(ChangeRequest $request): ApplyResult;

    /**
     * Whether this handler can undo an applied request.
     */
    public function supportsRevert(): bool;

    /**
     * Undoes an applied request, using snapshot_before and the ids recorded
     * in the apply result.
     */
    public function revert(ChangeRequest $request): ApplyResult;
}
