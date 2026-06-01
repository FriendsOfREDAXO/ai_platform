<?php

declare(strict_types=1);

/**
 * Deprecated MCP endpoint kept as a backward-compatibility shim.
 *
 * The canonical MCP endpoint is /mcp, dispatched by rex_ai_mcp_router.
 * This API function only exists so that existing Claude Desktop / Cursor
 * setups pointing at index.php?rex-api-call=ai_mcp keep working until
 * users migrate their configs.
 *
 * @deprecated since 1.0.0-beta2, use /mcp via rex_ai_mcp_router instead.
 */
class rex_api_ai_mcp extends rex_api_function
{
    protected $published = true;

    public function execute(): rex_api_result
    {
        $server = new rex_ai_mcp_server(new rex_ai_mcp_authenticator());
        $server->handle();

        // Unreachable — handle() always exits.
        return new rex_api_result(true);
    }
}
