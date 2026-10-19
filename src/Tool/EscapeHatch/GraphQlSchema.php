<?php

namespace FA\Mcp\Tool\EscapeHatch;

use FA\Mcp\Gateway\GraphQlGateway;
use FA\Mcp\Tool\Tool;
use FA\Mcp\Tool\ToolResult;
use GraphQL\Utils\SchemaPrinter;

/**
 * The caller's GraphQL schema as SDL, for writing graphql_query and
 * graphql_mutate documents (spec §6.4).
 */
final class GraphQlSchema implements Tool
{
    public function name(): string
    {
        return 'graphql_schema';
    }

    public function title(): string
    {
        return 'GraphQL schema';
    }

    public function description(): string
    {
        return 'Returns the FrontAccounting GraphQL schema as SDL (in the "sdl" field), with every type, field, '
            . 'argument and its documentation. Read it before writing a document for graphql_query or '
            . 'graphql_mutate; prefer a dedicated tool when one does the job.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function annotations(): array
    {
        return self::READ_ONLY;
    }

    public function requiresWrite(): bool
    {
        return false;
    }

    public function call(array $args, GraphQlGateway $gql): ToolResult
    {
        return ToolResult::ok(['sdl' => SchemaPrinter::doPrint($gql->schema())]);
    }
}
