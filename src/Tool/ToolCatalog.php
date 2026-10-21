<?php

namespace FA\Mcp\Tool;

use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Gateway\GraphQlGateway;
use FA\Mcp\Protocol\HttpStatusException;
use FA\Mcp\Protocol\RpcError;
use FA\Mcp\Protocol\ToolProvider;
use FA\Mcp\Tool\EscapeHatch\GraphQlMutate;
use FA\Mcp\Tool\EscapeHatch\GraphQlQuery;
use FA\Mcp\Tool\EscapeHatch\GraphQlSchema;
use GraphQL\Type\Schema;

/**
 * Serves tools/list and tools/call (spec §6.1, §6.5): the tools generated from
 * the caller's schema, then the escape hatch, with every requiresWrite() tool
 * hidden from a read connection.
 *
 * One instance lives for one HTTP request: the schema and the scope are each
 * fetched once, on first use, and so are the list tools' queryable fields
 * (QueryableFields), on the first tools/list; a count tool names its list's.
 * A hidden tool and a missing one are refused alike. An AuthFailure from the
 * gateway becomes the HttpStatusException the transport answers with.
 */
final class ToolCatalog implements ToolProvider
{
    private GraphQlGateway $gql;
    private SchemaToolFactory $factory;
    private bool $escapeHatch;
    /** @var callable(AuthFailure): HttpStatusException */
    private $authFailure;
    private ScopeProbe $scope;
    /** @var array<string, Tool>|null name => tool, in catalog order */
    private ?array $tools = null;
    /** @var array<string, string[]>|null list field => the fields its `query` can filter and sort on */
    private ?array $queryable = null;

    /**
     * @param callable|null $authFailure callable(AuthFailure): HttpStatusException;
     *                                   null maps UNAUTHENTICATED to 401 and FORBIDDEN to 403
     */
    public function __construct(
        GraphQlGateway $gql,
        SchemaToolFactory $factory,
        bool $escapeHatch,
        ?callable $authFailure = null
    ) {
        $this->gql = self::cachingSchema($gql);
        $this->factory = $factory;
        $this->escapeHatch = $escapeHatch;
        $this->authFailure = $authFailure ?? [self::class, 'defaultAuthFailure'];
        $this->scope = new ScopeProbe($this->gql);
    }

    /** UNAUTHENTICATED => 401 invalid_token, anything else (FORBIDDEN) => 403 NO_API_ACCESS. */
    public static function defaultAuthFailure(AuthFailure $e): HttpStatusException
    {
        if ($e->reason() === AuthFailure::UNAUTHENTICATED) {
            return new HttpStatusException(401, [], ['error' => 'invalid_token', 'message' => $e->getMessage()]);
        }
        return new HttpStatusException(403, [], ['error' => 'NO_API_ACCESS', 'message' => $e->getMessage()]);
    }

    public function listTools(): array
    {
        $descriptors = [];
        foreach ($this->visibleTools() as $tool) {
            if ($tool instanceof GeneratedTool && $tool->queryableList() !== null) {
                $tool = $tool->withQueryable($this->queryable()[$tool->queryableList()] ?? []);
            }
            $descriptors[] = [
                'name' => $tool->name(),
                'title' => $tool->title(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
                'annotations' => $tool->annotations(),
            ];
        }
        return $descriptors;
    }

    public function callTool(string $name, array $arguments): array
    {
        $tool = $this->allTools()[$name] ?? null;
        // A read-only tool runs on any connection, so only a write tool needs the scope.
        if ($tool !== null && $tool->requiresWrite() && $this->isReadConnection()) {
            $tool = null;
        }
        if ($tool === null) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Unknown tool: ' . $name);
        }

        $error = InputValidator::validate($tool->inputSchema(), $arguments);
        if ($error !== null) {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Invalid arguments: ' . $error);
        }

        try {
            return $tool->call($arguments, $this->gql)->toMcp();
        } catch (AuthFailure $e) {
            throw $this->httpStatus($e);
        }
    }

    /**
     * @return array<string, Tool> name => tool, in catalog order
     * @throws HttpStatusException for an AuthFailure
     */
    private function visibleTools(): array
    {
        $tools = $this->allTools();
        if (!$this->isReadConnection()) {
            return $tools;
        }
        return array_filter($tools, function (Tool $tool): bool {
            return !$tool->requiresWrite();
        });
    }

    /**
     * Every tool, whatever the scope. When two tools map to one name the first
     * in catalog order (generated before the escape hatch) is kept, and the
     * later one is skipped and logged.
     *
     * @return array<string, Tool> name => tool, in catalog order
     * @throws HttpStatusException for an AuthFailure
     */
    private function allTools(): array
    {
        if ($this->tools === null) {
            try {
                $tools = $this->factory->fromSchema($this->gql->schema());
            } catch (AuthFailure $e) {
                throw $this->httpStatus($e);
            }
            if ($this->escapeHatch) {
                array_push($tools, new GraphQlSchema(), new GraphQlQuery(), new GraphQlMutate());
            }
            $this->tools = [];
            foreach ($tools as $tool) {
                $name = $tool->name();
                if (isset($this->tools[$name])) {
                    error_log('mcp: tool name collision on ' . $name . '; keeping the first, skipping '
                        . get_class($tool));
                    continue;
                }
                $this->tools[$name] = $tool;
            }
        }
        return $this->tools;
    }

    /**
     * QueryableFields' for every list tool, read once from the schema (probed for
     * a list whose description lacks them), on the first
     * tools/list (a call needs no description).
     *
     * @return array<string, string[]>
     * @throws HttpStatusException for an AuthFailure
     */
    private function queryable(): array
    {
        if ($this->queryable === null) {
            $lists = [];
            foreach ($this->allTools() as $tool) {
                if ($tool instanceof GeneratedTool && $tool->listField() !== null) {
                    $lists[] = $tool->listField();
                }
            }
            try {
                $this->queryable = QueryableFields::fromSchema($this->gql, $this->gql->schema(), $lists);
            } catch (AuthFailure $e) {
                throw $this->httpStatus($e);
            }
        }
        return $this->queryable;
    }

    /** @throws HttpStatusException for an AuthFailure */
    private function isReadConnection(): bool
    {
        try {
            return $this->scope->scope() === ScopeProbe::READ;
        } catch (AuthFailure $e) {
            throw $this->httpStatus($e);
        }
    }

    private function httpStatus(AuthFailure $e): HttpStatusException
    {
        return call_user_func($this->authFailure, $e);
    }

    /** $gql with schema() fetched once, so graphql_schema reuses the catalog's. */
    private static function cachingSchema(GraphQlGateway $gql): GraphQlGateway
    {
        return new class ($gql) implements GraphQlGateway {
            private GraphQlGateway $inner;
            private ?Schema $schema = null;

            public function __construct(GraphQlGateway $inner)
            {
                $this->inner = $inner;
            }

            public function execute(string $document, array $variables = [], ?string $operationName = null): array
            {
                return $this->inner->execute($document, $variables, $operationName);
            }

            public function schema(): Schema
            {
                if ($this->schema === null) {
                    $this->schema = $this->inner->schema();
                }
                return $this->schema;
            }
        };
    }
}
