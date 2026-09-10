<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

/**
 * The values a change request wants written.
 *
 * Payloads carry only fields the corresponding REDAXO service actually
 * accepts — nothing is invented. They are built through typed setters that
 * validate slot ranges, field names and value shapes up front, so an
 * invalid payload cannot be constructed in the first place.
 *
 * Patch semantics: only fields that were explicitly set are part of the
 * payload. Setting a field to an empty string clears it; not setting it
 * leaves the stored value untouched. That distinction matters — "don't
 * touch value7" and "empty value7" must not collapse into one.
 */
interface PayloadInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): static;

    /**
     * Whether anything at all was set. An empty payload is rejected by
     * ChangeService for create/update operations.
     */
    public function isEmpty(): bool;

    /**
     * Handles of staged files this payload points at.
     *
     * Almost every payload returns an empty list. MediaPayload returns the
     * upload handle when one was set, and `ChangeService::propose()` uses that to
     * link the staged file to the request it now belongs to.
     *
     * Why on the interface rather than a special case in ChangeService: the core
     * knows nothing about media, and it must not learn. Anything that can carry a
     * file answers this the same way, and the alternative — searching every
     * payload's JSON for handle-shaped strings — is a table scan pretending to be
     * a relation.
     *
     * @return list<string>
     */
    public function attachments(): array;
}
