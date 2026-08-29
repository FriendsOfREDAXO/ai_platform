<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Agent;

use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangePayloadBuilder;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry;
use FriendsOfRedaxo\AiPlatform\Change\Source;
use InvalidArgumentException;
use JsonException;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Throwable;

use function is_array;

/**
 * Lets an agent running inside REDAXO file a change request.
 *
 * Registered through AI_PLATFORM_AGENT_TOOLS, deliberately not through
 * AI_PLATFORM_MCP_TOOLS: MCP is where a project exposes tools of its own
 * choosing to external clients, and change requests are not something this
 * addon should push into that surface unasked. A project that does want them
 * over MCP writes a tool of its own calling ChangeService.
 *
 * `target` and `fields` are JSON strings rather than typed parameters because
 * Symfony AI derives a tool's schema from the method signature by reflection,
 * and the fields of a metainfo or YForm change are only known per installation
 * at runtime. {@see DescribeChangeTypesTool} is how the agent looks up what
 * belongs in them instead of guessing.
 */
#[AsTool(
    name: 'redaxo_propose_change',
    description: <<<'TXT'
        Proposes a change to REDAXO content. Nothing is written by this call: the proposal is
        queued for a human editor to review and approve. Call redaxo_describe_change_types first
        to learn which types, operations and field names exist in this installation.

        Arguments:
          type       change type, e.g. "slice", "article", "category", "meta", "media", "yform"
          operation  "create", "update" or "delete"
          target     JSON object addressing what to change, e.g. {"article_id":12,"slice_id":345}
          fields     JSON object of field values, e.g. {"value1":"New headline"}; ignored for delete
          reason     why the change is proposed — the editor decides based on this text, so be specific
          changeset  optional package name; proposals sharing one can be approved together
        TXT,
)]
final class ProposeChangeTool
{
    public function __construct(
        private readonly string $sourceKey = 'agent',
        private readonly string $sourceLabel = '',
    ) {
    }

    public function __invoke(
        string $type,
        string $operation,
        string $target,
        string $fields = '{}',
        string $reason = '',
        string $changeset = '',
    ): string {
        try {
            $handler = HandlerRegistry::get($type);
            if (null === $handler) {
                return sprintf(
                    'Unknown change type "%s". Available: %s',
                    $type,
                    implode(', ', array_keys(HandlerRegistry::all())),
                );
            }

            $operationCase = ChangeOperation::tryFrom($operation);
            if (null === $operationCase) {
                return sprintf('Unknown operation "%s". Use create, update or delete.', $operation);
            }

            $built = ChangePayloadBuilder::build(
                $handler,
                $operationCase,
                self::decodeJson($target, 'target'),
                self::decodeJson($fields, 'fields'),
            );

            $service = ChangeService::getInstance();
            $source = Source::agent(
                $this->sourceKey,
                '' !== $this->sourceLabel ? $this->sourceLabel : $this->sourceKey,
            );

            $id = ChangeOperation::Delete === $operationCase
                ? $service->proposeDelete($built['target'], $reason, $source, '' === $changeset ? null : $changeset)
                : $service->propose($built['target'], $built['payload'], $reason, $source, '' === $changeset ? null : $changeset);

            return sprintf(
                'Change request #%d created for %s. It is queued for editorial review and has NOT been applied.',
                $id,
                $built['target']->describe(),
            );
        } catch (Throwable $e) {
            // Returned rather than thrown, so the agent can read what went
            // wrong and correct its next attempt instead of just failing.
            return 'Could not create the change request: ' . $e->getMessage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $raw, string $what): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(sprintf('"%s" is not valid JSON: %s', $what, $e->getMessage()));
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException(sprintf('"%s" must be a JSON object.', $what));
        }

        return $decoded;
    }
}
