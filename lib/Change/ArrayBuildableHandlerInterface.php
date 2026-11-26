<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change;

use InvalidArgumentException;

/**
 * A handler that can turn loose arrays into its own target and payload.
 *
 * Without it a type registered via AI_PLATFORM_CHANGE_HANDLERS could only be
 * proposed from PHP: the REST API, the agent tools and the type catalogue all
 * go through {@see ChangePayloadBuilder}, which knows the built-in types by
 * name and nothing else. A handler implementing this interface takes over the
 * mapping for its own type, so its proposals arrive through every entry point
 * like the built-in ones.
 *
 * Optional on purpose: existing handlers keep working unchanged, they just
 * stay PHP-only.
 */
interface ArrayBuildableHandlerInterface extends HandlerInterface
{
    /**
     * Builds target and payload from a caller's name/value maps.
     *
     * `$fields` arrives normalised: scalar values as strings, empty entries
     * already dropped (see ChangePayloadBuilder::normaliseFields()). Route
     * them through the payload's typed setters rather than around them, so
     * a REST caller meets the same guards as PHP code.
     *
     * The target must belong to this handler — its changeType() has to match
     * getType() — and the payload must be an instance of payloadClass().
     *
     * @param array<string, mixed> $target
     * @param array<string, string> $fields
     * @throws InvalidArgumentException on missing or malformed input; the
     *         message reaches the caller, so make it say what to fix
     * @return array{target: TargetInterface, payload: PayloadInterface}
     */
    public function buildFromArray(ChangeOperation $operation, array $target, array $fields): array;

    /**
     * Target and field names a caller may use, for the type catalogue
     * (`GET /changes/describe` without parameters) and the describe tool.
     *
     * `target` lists the target keys, with the operation in parentheses where
     * a key only applies to one (`article_id (update)`). `fields` maps each
     * writable field name to a short description.
     *
     * @return array{target: list<string>, fields: array<string, string>}
     */
    public function describeFields(): array;
}
