<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Change\Agent;

use FriendsOfRedaxo\AiPlatform\Change\ChangePayloadBuilder;
use FriendsOfRedaxo\AiPlatform\Change\HandlerRegistry;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Throwable;

/**
 * Tells an agent what it may propose in this installation.
 *
 * Necessary because the interesting field sets are not static: metainfo fields
 * and YForm tables differ per site, and a slice's slots are only meaningful in
 * the context of a module. Without this an agent would guess field names, and
 * every guess becomes a request an editor has to reject.
 */
#[AsTool(
    name: 'redaxo_describe_change_types',
    description: <<<'TXT'
        Lists the change types available in this REDAXO installation, the fields each one
        addresses its target by, and the field names that can be written. Call this before
        redaxo_propose_change so target and field names are correct rather than guessed.
        TXT,
)]
final class DescribeChangeTypesTool
{
    public function __invoke(): string
    {
        try {
            $description = [];

            foreach (ChangePayloadBuilder::describe() as $type => $info) {
                $handler = HandlerRegistry::get($type);
                if (null === $handler) {
                    continue;
                }

                $operations = [];
                foreach ($handler->supportedOperations() as $operation) {
                    $operations[] = $operation->value;
                }

                $description[$type] = [
                    'label' => $handler->getLabel(),
                    'operations' => $operations,
                    'target_fields' => $info['target'],
                    'writable_fields' => $info['fields'],
                ];

                // Slices only: which slots each module actually reads. The flat
                // slot list cannot express that, and an agent without it writes
                // into value1 and hopes.
                if (isset($info['modules'])) {
                    $description[$type]['modules'] = $info['modules'];
                }
            }

            if ([] === $description) {
                return 'No change types are registered in this installation.';
            }

            return json_encode($description, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            return 'Could not describe the change types: ' . $e->getMessage();
        }
    }
}
