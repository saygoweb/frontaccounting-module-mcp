<?php

namespace FA\Mcp\Tool\EscapeHatch;

use FA\Mcp\Gateway\GraphQlGateway;
use FA\Mcp\Tool\Tool;
use FA\Mcp\Tool\ToolResult;

/**
 * Runs an arbitrary GraphQL document that may change things (spec §6.4).
 *
 * Listed only for a connection with the write scope. The document runs as
 * given: graphql checks the scope and each field's access areas, so nothing
 * here needs to parse it.
 */
final class GraphQlMutate implements Tool
{
    public function name(): string
    {
        return 'graphql_mutate';
    }

    public function title(): string
    {
        return 'GraphQL mutation';
    }

    public function description(): string
    {
        return 'Runs a GraphQL mutation against FrontAccounting as this connection\'s user and returns its data. '
            . 'Use it only for what no dedicated tool covers; graphql_schema gives the schema. Mutations in one '
            . 'document are not one transaction, and deletes follow FrontAccounting\'s rules (void or cancel).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mutation' => ['type' => 'string', 'minLength' => 1, 'description' => 'The GraphQL document.'],
                'variables' => ['type' => 'object', 'description' => 'Values for the document\'s variables.'],
                'operationName' => [
                    'type' => 'string',
                    'description' => 'Which operation to run, if the document has several.',
                ],
            ],
            'required' => ['mutation'],
            'additionalProperties' => false,
        ];
    }

    public function annotations(): array
    {
        return [
            'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false,
        ];
    }

    public function requiresWrite(): bool
    {
        return true;
    }

    public function call(array $args, GraphQlGateway $gql): ToolResult
    {
        return ToolResult::fromGraphQl($gql->execute(
            (string)$args['mutation'],
            $args['variables'] ?? [],
            $args['operationName'] ?? null
        ));
    }
}
