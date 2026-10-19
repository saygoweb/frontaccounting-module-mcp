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
 * fetched once, on first use. A hidden tool and a missing one are refused
 * alike. An AuthFailure from the gateway becomes the HttpStatusException the
 * transport answers with.
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
        $tool = $this->visibleTools()[$name] ?? null;
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
        try {
            if ($this->tools === null) {
                $tools = $this->factory->fromSchema($this->gql->schema());
                if ($this->escapeHatch) {
                    array_push($tools, new GraphQlSchema(), new GraphQlQuery(), new GraphQlMutate());
                }
                $this->tools = [];
                foreach ($tools as $tool) {
                    $this->tools[$tool->name()] = $tool;
                }
            }
            if ($this->scope->scope() !== ScopeProbe::READ) {
                return $this->tools;
            }
        } catch (AuthFailure $e) {
            throw $this->httpStatus($e);
        }
        return array_filter($this->tools, function (Tool $tool): bool {
            return !$tool->requiresWrite();
        });
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
