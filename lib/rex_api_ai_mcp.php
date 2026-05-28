<?php

declare(strict_types=1);

/**
 * MCP (Model Context Protocol) HTTP Endpoint.
 *
 * Implements a Streamable HTTP MCP server that other AI tools can connect to.
 * Other REDAXO addons can register tools via the AI_PLATFORM_MCP_TOOLS extension point.
 *
 * Endpoint: index.php?rex-api-call=ai_mcp
 */
class rex_api_ai_mcp extends rex_api_function
{
    protected $published = true;

    private const PROTOCOL_VERSION = '2025-03-26';
    private const SERVER_NAME = 'REDAXO AI Platform MCP Server';

    public function execute(): rex_api_result
    {
        // Clean any output buffers from REDAXO
        rex_response::cleanOutputBuffers();

        // Check if MCP is enabled
        if (!rex_config::get('ai_platform', 'mcp_enabled', false)) {
            $this->sendHttpError(503, 'MCP server is disabled');
        }

        // Authenticate via token
        $this->authenticate();

        // Read JSON-RPC request
        $input = file_get_contents('php://input');
        if (false === $input || '' === $input) {
            $this->sendHttpError(400, 'Empty request body');
        }

        $request = json_decode($input, true);
        if (null === $request) {
            $this->sendHttpError(400, 'Invalid JSON');
        }

        $method = $request['method'] ?? '';
        $id = $request['id'] ?? null;
        $params = $request['params'] ?? [];

        // Notifications (no id) don't require a response
        if (null === $id) {
            header('Content-Type: application/json');
            http_response_code(202);
            exit;
        }

        $result = match ($method) {
            'initialize' => $this->handleInitialize($params),
            'tools/list' => $this->handleToolsList(),
            'tools/call' => $this->handleToolsCall($params),
            'ping' => new \stdClass(),
            default => null,
        };

        if (null === $result) {
            $this->sendJsonRpcError($id, -32601, 'Method not found: ' . $method);
        }

        $this->sendJsonRpcResult($id, $result);

        // Unreachable, but required by return type
        return new rex_api_result(true);
    }

    private function authenticate(): void
    {
        $token = rex_config::get('ai_platform', 'mcp_token', '');
        if ('' === $token) {
            return;
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Accept both "Bearer <token>" and raw "<token>"
        if (str_starts_with($authHeader, 'Bearer ')) {
            $providedToken = substr($authHeader, 7);
        } else {
            $providedToken = $authHeader;
        }

        if ('' === $providedToken || !hash_equals($token, $providedToken)) {
            $this->sendHttpError(401, 'Unauthorized');
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleInitialize(array $params): array
    {
        $result = [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => new \stdClass(),
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => rex_addon::get('ai_platform')->getVersion(),
            ],
        ];

        $description = rex_config::get('ai_platform', 'mcp_description', '');
        if ('' !== $description) {
            $result['instructions'] = $description;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function handleToolsList(): array
    {
        $tools = $this->collectTools();
        $toolList = [];
        foreach ($tools as $tool) {
            $toolList[] = $tool->toListEntry();
        }

        return ['tools' => $toolList];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $tools = $this->collectTools();
        $tool = $tools[$toolName] ?? null;

        if (null === $tool) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'Error: Tool not found: ' . $toolName],
                ],
                'isError' => true,
            ];
        }

        try {
            $result = $tool->execute($arguments);

            if (is_string($result)) {
                $content = [['type' => 'text', 'text' => $result]];
            } elseif (is_array($result)) {
                $content = $result;
            } else {
                $content = [['type' => 'text', 'text' => json_encode($result, JSON_THROW_ON_ERROR)]];
            }

            return [
                'content' => $content,
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'Error: ' . $e->getMessage()],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * Collect all tools from addons via extension point.
     *
     * @return array<string, rex_ai_mcp_tool>
     */
    private function collectTools(): array
    {
        /** @var array<string, rex_ai_mcp_tool> $tools */
        $tools = rex_extension::registerPoint(new rex_extension_point(
            'AI_PLATFORM_MCP_TOOLS',
            [],
        ));

        return $tools;
    }

    /**
     * @param string|int $id
     * @param array<string, mixed>|object $result
     */
    private function sendJsonRpcResult(mixed $id, array|object $result): never
    {
        header('Content-Type: application/json');
        http_response_code(200);

        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param string|int $id
     */
    private function sendJsonRpcError(mixed $id, int $code, string $message): never
    {
        header('Content-Type: application/json');
        http_response_code(200);

        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send HTTP error for pre-request failures (auth, disabled, etc.)
     * These are NOT JSON-RPC errors but HTTP-level errors.
     */
    private function sendHttpError(int $statusCode, string $message): never
    {
        http_response_code($statusCode);
        header('Content-Type: text/plain');
        echo $message;
        exit;
    }
}
