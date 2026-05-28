<?php

declare(strict_types=1);

/**
 * Value object representing a tool that can be exposed via the MCP server.
 */
class rex_ai_mcp_tool
{
    /**
     * @param string $name Unique tool name
     * @param string $description Human-readable description
     * @param array<string, mixed> $inputSchema JSON Schema for input parameters
     * @param callable(array<string, mixed>): mixed $handler Function that executes the tool
     */
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
        private readonly mixed $handler,
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
    public function execute(array $arguments): mixed
    {
        return ($this->handler)($arguments);
    }
}
