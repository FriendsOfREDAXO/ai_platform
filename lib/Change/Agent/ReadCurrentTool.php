<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Agent;

use FriendsOfRedaxo\AiPlatform\Change\ChangeOperation;
use FriendsOfRedaxo\AiPlatform\Change\ChangePayloadBuilder;
use FriendsOfRedaxo\AiPlatform\Change\ChangeService;
use FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry;
use InvalidArgumentException;
use JsonException;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Throwable;

use function is_array;

/**
 * Reads the current values of something an agent might want to change.
 *
 * An agent needs the present state to formulate a sensible proposal — a
 * rewritten headline is only sensible relative to the old one. This is not a
 * prerequisite for proposing: ChangeService reads the state itself when a
 * request comes in, which is what makes stale detection work at review time.
 */
#[AsTool(
    name: 'redaxo_read_current',
    description: <<<'TXT'
        Reads the current field values of a REDAXO object, so a proposed change can be based on
        what is actually there. Takes the same "type" and "target" arguments as
        redaxo_propose_change. Read-only.
        TXT,
)]
final class ReadCurrentTool
{
    public function __invoke(string $type, string $target): string
    {
        try {
            $handler = HandlerRegistry::get($type);
            if (null === $handler) {
                return sprintf(
                    'Unknown change type "%s". Available: %s',
                    $type,
                    implode(', ', array_keys(HandlerRegistry::all())),
                );
            }

            $targetData = self::decodeJson($target);

            // An update target is the right shape for reading: it addresses
            // something that already exists.
            $built = ChangePayloadBuilder::build($handler, ChangeOperation::Update, $targetData, []);
            $state = ChangeService::getInstance()->read($built['target']);

            if (!$state->exists()) {
                return sprintf('%s does not exist.', $state->description);
            }

            return json_encode([
                'target' => $state->description,
                'values' => $state->values,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            return 'Could not read the current state: ' . $e->getMessage();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('"target" is not valid JSON: ' . $e->getMessage());
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('"target" must be a JSON object.');
        }

        return $decoded;
    }
}
