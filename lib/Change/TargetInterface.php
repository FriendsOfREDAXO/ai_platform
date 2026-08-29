<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

/**
 * Addresses what a change request points at.
 *
 * Every change type ships exactly one target class. The class is created
 * through named constructors that also fix the operation — `existing()`
 * yields an update, `createIn()` a create. A caller therefore cannot build
 * the impossible combination of "create something that already exists".
 *
 * Targets are immutable value objects and round-trip through JSON via
 * {@see toArray()} / {@see fromArray()} because they are persisted in the
 * `target` column.
 */
interface TargetInterface
{
    /**
     * The change type this target belongs to, e.g. `slice`. Must match the
     * handler's getType().
     */
    public static function changeType(): string;

    public function operation(): ChangeOperation;

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static;

    /**
     * Short human-readable description for lists and diff headings, e.g.
     * "Artikel 12 »Startseite« (de), Slice #345". Resolves names at call
     * time, so it must cope with the target having been deleted meanwhile.
     */
    public function describe(): string;

    /**
     * Deep link into the REDAXO backend where a reviewer can inspect the
     * target, or null when there is no such page.
     */
    public function backendUrl(): ?string;
}
