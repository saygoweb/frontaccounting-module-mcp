<?php

namespace FA\Mcp\Tool;

use FA\Mcp\Gateway\GraphQlGateway;

/**
 * One MCP tool: a description the model reads, a JSON Schema for its
 * arguments, and a call that turns them into GraphQL (spec §6.2).
 */
interface Tool
{
    /** The annotations of every read-only tool. */
    public const READ_ONLY = [
        'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false,
    ];

    /** The MCP tool name, snake_case. */
    public function name(): string;

    /** A short human-readable title. */
    public function title(): string;

    /** What the tool does and returns. */
    public function description(): string;

    /**
     * A JSON Schema object with additionalProperties: false, as InputValidator
     * supports; empty properties are a \stdClass so they encode as {}.
     */
    public function inputSchema(): array;

    /** readOnlyHint, destructiveHint, idempotentHint, openWorldHint. */
    public function annotations(): array;

    /** True if the tool is listed only for a connection with the write scope. */
    public function requiresWrite(): bool;

    /**
     * Run the tool. $args has already been validated against inputSchema().
     *
     * @throws \FA\Mcp\Gateway\AuthFailure from the gateway
     */
    public function call(array $args, GraphQlGateway $gql): ToolResult;
}
