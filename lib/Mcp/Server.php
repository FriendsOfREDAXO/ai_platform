<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\AiPlatform\Mcp;

use rex_addon;
use rex_config;
use rex_extension;
use rex_extension_point;
use rex_response;
use stdClass;
use Throwable;

/**
 * MCP (Model Context Protocol) JSON-RPC 2.0 server.
 *
 * Implements the streamable-HTTP MCP transport. The router dispatches POST
 * requests to {@see handle()}. Auth is delegated to Authenticator:
 * tools/list returns only tools callable by the resolved context, tools/call
 * enforces the per-tool requirements and returns a 401 challenge for missing
 * authentication so MCP clients can start the OAuth flow.
 */
final class Server
{
    private const PROTOCOL_VERSION = '2025-03-26';
    private const SERVER_NAME = 'REDAXO AI Platform MCP Server';

    public function __construct(
        private readonly Authenticator $authenticator,
    ) {
    }

    /**
     * Handles a single MCP JSON-RPC request. Always exits.
     */
    public function handle(): never
    {
        rex_response::cleanOutputBuffers();

        if (!rex_config::get('ai_platform', 'mcp_enabled', false)) {
            $this->sendHttpError(503, 'MCP server is disabled');
        }

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

        // Notifications (no id) are acknowledged without a body
        if (null === $id) {
            header('Content-Type: application/json');
            http_response_code(202);
            exit;
        }

        try {
            $context = $this->authenticator->authenticate();

            // Optional: require authentication for the whole endpoint. Without
            // this, anonymous clients get a 200 on initialize/tools/list and
            // never receive the 401 that triggers their OAuth flow — so they
            // stay anonymous and protected tools never surface. With it on, an
            // unauthenticated request is challenged so the client logs in.
            if (!$context->isAuthenticated() && rex_config::get('ai_platform', 'mcp_require_auth', false)) {
                throw new AuthRequiredException('Authentication required');
            }

            $result = match ($method) {
                'initialize' => $this->handleInitialize($params),
                'tools/list' => $this->handleToolsList($context),
                'tools/call' => $this->handleToolsCall($params, $context),
                'ping' => new stdClass(),
                default => null,
            };
        } catch (InvalidTokenException $e) {
            $this->sendAuthChallenge($id, $e->getMessage(), 'invalid_token');
        } catch (AuthRequiredException $e) {
            $this->sendAuthChallenge($id, $e->getMessage());
        }

        if (null === $result) {
            $this->sendJsonRpcError($id, -32601, 'Method not found: ' . $method);
        }

        $this->sendJsonRpcResult($id, $result);
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
                'tools' => new stdClass(),
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
     * Lists only tools the current context may actually call.
     *
     * @return array<string, mixed>
     */
    private function handleToolsList(Context $context): array
    {
        $tools = self::collectTools();
        $toolList = [];
        foreach ($tools as $tool) {
            if (!self::isToolEnabled($tool->getName())) {
                continue;
            }
            if (!$tool->isCallableBy($context)) {
                continue;
            }
            $toolList[] = $tool->toListEntry();
        }

        return ['tools' => $toolList];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(array $params, Context $context): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $tools = self::collectTools();
        $tool = $tools[$toolName] ?? null;
        if (null !== $tool && !self::isToolEnabled($toolName)) {
            // Disabled tools behave as if they were never registered.
            $tool = null;
        }

        if (null === $tool) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'Error: Tool not found: ' . $toolName],
                ],
                'isError' => true,
            ];
        }

        if (!$tool->isPublic() && !$context->isAuthenticated()) {
            throw new AuthRequiredException('Authentication required for tool: ' . $toolName);
        }

        if (!$tool->isCallableBy($context)) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'Error: Missing required scope for tool: ' . $toolName],
                ],
                'isError' => true,
            ];
        }

        try {
            $result = $tool->execute($arguments, $context);

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
        } catch (Throwable $e) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => 'Error: ' . $e->getMessage()],
                ],
                'isError' => true,
            ];
        }
    }

    /**
     * Collect all registered tools via the AI_PLATFORM_MCP_TOOLS extension point.
     *
     * @return array<string, Tool>
     */
    public static function collectTools(): array
    {
        /** @var array<string, Tool> $tools */
        $tools = rex_extension::registerPoint(new rex_extension_point(
            'AI_PLATFORM_MCP_TOOLS',
            [],
        ));

        return $tools;
    }

    /**
     * Tool names the admin has switched off via the backend. Disabled tools
     * are hidden from tools/list and rejected by tools/call. Stored as a JSON
     * list under the `mcp_disabled_tools` config key (opt-out: tools are
     * enabled by default, so newly registered tools stay available).
     *
     * @return list<string>
     */
    public static function disabledTools(): array
    {
        $raw = rex_config::get('ai_platform', 'mcp_disabled_tools', '');
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_string'));
    }

    public static function isToolEnabled(string $name): bool
    {
        return !in_array($name, self::disabledTools(), true);
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
     * Sends a 401 response with WWW-Authenticate so MCP clients can start
     * (or repeat) the OAuth flow against /.well-known/oauth-protected-resource.
     *
     * @param string|null $oauthError Optional RFC 6750 §3.1 error code, e.g.
     *                                "invalid_token" when the supplied bearer
     *                                token did not validate.
     */
    private function sendAuthChallenge(mixed $id, string $message, ?string $oauthError = null): never
    {
        header('Content-Type: application/json');
        header('WWW-Authenticate: ' . Authenticator::buildChallengeHeader($oauthError, $oauthError !== null ? $message : null));
        http_response_code(401);

        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32001,
                'message' => $message,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param int $statusCode
     */
    private function sendHttpError(int $statusCode, string $message): never
    {
        http_response_code($statusCode);
        header('Content-Type: text/plain');
        echo $message;
        exit;
    }
}
