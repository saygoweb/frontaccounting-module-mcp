<?php

namespace FA\Mcp\Tool\EscapeHatch;

use FA\Mcp\Gateway\GraphQlGateway;
use FA\Mcp\Tool\Tool;
use FA\Mcp\Tool\ToolResult;
use GraphQL\Error\SyntaxError;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;

/**
 * Runs an arbitrary read-only GraphQL document (spec §6.4).
 *
 * The document is parsed here and refused if any operation in it is not a
 * query. That is what makes the readOnlyHint true, and what keeps this tool
 * listed for a read connection: a client may auto-approve a read-only tool,
 * so a mutation must not be able to ride in on one - operationName cannot be
 * trusted to pick the query, since it is the caller's to set. Everything else
 * (depth, complexity, scope, access areas) is left to graphql's Executor,
 * which the gateway runs the document through.
 */
final class GraphQlQuery implements Tool
{
    public function name(): string
    {
        return 'graphql_query';
    }

    public function title(): string
    {
        return 'GraphQL query';
    }

    public function description(): string
    {
        return 'Runs a read-only GraphQL query against FrontAccounting as this connection\'s user and returns '
            . 'its data. Use it for what no dedicated tool covers; graphql_schema gives the schema. Documents '
            . 'containing a mutation or subscription are refused.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'minLength' => 1, 'description' => 'The GraphQL document.'],
                'variables' => ['type' => 'object', 'description' => 'Values for the document\'s variables.'],
                'operationName' => [
                    'type' => 'string',
                    'description' => 'Which operation to run, if the document has several.',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
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
        $document = (string)$args['query'];
        try {
            $ast = Parser::parse($document);
        } catch (SyntaxError $e) {
            return ToolResult::error('GraphQL syntax error: ' . $e->getMessage());
        }

        foreach ($ast->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode && $definition->operation !== 'query') {
                return ToolResult::error('graphql_query runs queries only; use graphql_mutate for mutations');
            }
        }

        return ToolResult::fromGraphQl($gql->execute(
            $document,
            $args['variables'] ?? [],
            $args['operationName'] ?? null
        ));
    }
}
