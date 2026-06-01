<?php

declare(strict_types=1);

/**
 * Value object representing a tool that can be exposed via the MCP server.
 *
 * Tools declare their auth requirements at registration time. The server
 * enforces them before invoking the handler:
 *
 *   - public=true:     callable without authentication
 *   - public=false:    requires an authenticated context
 *   - requiredScopes:  additional scope check on top of authentication
 *
 * Handlers receive the resolved auth context as a second argument and can
 * adapt their output to the caller (e.g. hide non-public data for
 * anonymous callers, scope query results to the YCom user).
 */
class rex_ai_mcp_tool
{
    /**
     * @param string $name Unique tool name
     * @param string $description Human-readable description
     * @param array<string, mixed> $inputSchema JSON Schema for input parameters
     * @param callable(array<string, mixed>, rex_ai_mcp_context): mixed $handler
     * @param bool $public Whether the tool can be called without auth
     * @param list<string> $requiredScopes Scopes the caller must have (in addition to being authenticated)
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
        private readonly mixed $handler,
        private readonly bool $public = false,
        private readonly array $requiredScopes = [],
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * @return list<string>
     */
    public function getRequiredScopes(): array
    {
        return $this->requiredScopes;
    }

    /**
     * Whether this tool is callable in the given auth context.
     */
    public function isCallableBy(rex_ai_mcp_context $context): bool
    {
        if ($this->public) {
            return true;
        }
        if (!$context->isAuthenticated()) {
            return false;
        }
        return $context->hasAllScopes($this->requiredScopes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toListEntry(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, rex_ai_mcp_context $context): mixed
    {
        return ($this->handler)($arguments, $context);
    }
}
