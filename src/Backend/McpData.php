<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Backend;

use Redaxo\Core\Form\Field\Textarea;
use Redaxo\Core\Translation\Translatable;

/**
 * Formulardaten der MCP-Seite.
 *
 * @internal
 */
final class McpData
{
    #[Textarea(new Translatable('ai_platform_mcp_instructions'), note: new Translatable('ai_platform_mcp_instructions_notice'), rows: 4, maxLength: 4000)]
    public ?string $instructions = null;
}
