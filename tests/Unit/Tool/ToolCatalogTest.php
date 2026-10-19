<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Protocol\HttpStatusException;
use FA\Mcp\Protocol\RpcError;
use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\SchemaToolFactory;
use FA\Mcp\Tool\ScopeProbe;
use FA\Mcp\Tool\ToolCatalog;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;

class ToolCatalogTest extends TestCase
{
    private const GENERATED = [
        'customer_list', 'me', 'recurring_due_list', 'customer_create', 'customer_delete', 'customer_update',
        'invoice_email',
    ];

    private static function gateway(?string $scope = 'write'): FakeGraphQlGateway
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $me = $scope === null ? ['login' => 'admin'] : ['scope' => $scope];
        return $gql->on('me { scope }', ['data' => ['me' => $me]]);
    }

    private static function catalog(
        FakeGraphQlGateway $gql,
        bool $escapeHatch = true,
        ?callable $authFailure = null
    ): ToolCatalog {
        return new ToolCatalog($gql, new SchemaToolFactory(50), $escapeHatch, $authFailure);
    }

    private static function names(array $descriptors): array
    {
        return array_map(function (array $d): string {
            return $d['name'];
        }, $descriptors);
    }

    public function testWriteListsEverythingWithTheEscapeHatchLast(): void
    {
        $tools = self::catalog(self::gateway('write'))->listTools();
        $this->assertSame(
            array_merge(self::GENERATED, ['graphql_schema', 'graphql_query', 'graphql_mutate']),
            self::names($tools)
        );
        foreach ($tools as $descriptor) {
            $this->assertSame(['name', 'title', 'description', 'inputSchema', 'annotations'], array_keys($descriptor));
            $this->assertSame('object', $descriptor['inputSchema']['type']);
        }
    }

    public function testReadHidesWriteTools(): void
    {
        $this->assertSame(
            ['customer_list', 'me', 'recurring_due_list', 'graphql_schema', 'graphql_query'],
            self::names(self::catalog(self::gateway('read'))->listTools())
        );
    }

    public function testEscapeHatchOff(): void
    {
        $this->assertSame(self::GENERATED, self::names(self::catalog(self::gateway('write'), false)->listTools()));
    }

    public function testCallingAHiddenToolIsInvalidParams(): void
    {
        $gql = self::gateway('read');
        try {
            self::catalog($gql)->callTool('customer_create', ['input' => [['name' => 'N']]]);
            $this->fail('expected RpcError');
        } catch (RpcError $e) {
            $this->assertSame(RpcError::INVALID_PARAMS, $e->rpcCode());
            $this->assertSame('Unknown tool: customer_create', $e->getMessage());
        }
        $this->assertSame([], $gql->callsContaining('customerCreate'));
    }

    public function testCallingAnEscapeHatchToolWhenItIsOffIsInvalidParams(): void
    {
        $this->expectException(RpcError::class);
        $this->expectExceptionMessage('Unknown tool: graphql_query');
        self::catalog(self::gateway('write'), false)->callTool('graphql_query', ['query' => '{ me { login } }']);
    }

    public function testCallingAnUnknownToolIsInvalidParams(): void
    {
        $this->expectException(RpcError::class);
        $this->expectExceptionMessage('Unknown tool: nope');
        self::catalog(self::gateway())->callTool('nope', []);
    }

    public function testInvalidArgumentsAreInvalidParamsWithThePath(): void
    {
        $gql = self::gateway();
        try {
            self::catalog($gql)->callTool('customer_list', ['query' => ['limit' => 'x']]);
            $this->fail('expected RpcError');
        } catch (RpcError $e) {
            $this->assertSame(RpcError::INVALID_PARAMS, $e->rpcCode());
            $this->assertSame('Invalid arguments: /query/limit: expected integer or null', $e->getMessage());
        }
        // GraphQL is never called with bad arguments.
        $this->assertSame([], $gql->callsContaining('customerList'));
    }

    public function testCallReturnsTheCallToolResult(): void
    {
        $gql = self::gateway();
        $result = self::catalog($gql)->callTool('customer_list', ['query' => ['limit' => 5]]);
        $this->assertSame(['data' => []], $result['structuredContent']);
        $this->assertFalse($result['isError']);
        $this->assertCount(1, $gql->callsContaining('customerList'));
    }

    public function testScopeAndSchemaAreLoadedOncePerCatalog(): void
    {
        $gql = self::gateway();
        $catalog = self::catalog($gql);
        $catalog->listTools();
        $catalog->callTool('customer_list', []);
        $catalog->callTool('graphql_schema', []);
        $catalog->listTools();
        $this->assertCount(1, $gql->callsContaining('me { scope }'));
        $this->assertSame(1, $gql->schemaCalls);
    }

    public function testASchemaWithoutViewerScopeMeansWrite(): void
    {
        $schema = BuildSchema::build('type Viewer { login: String! } type Query { me: Viewer! }');
        $gql = new FakeGraphQlGateway(['data' => []], $schema);
        $this->assertSame('write', (new ScopeProbe($gql))->scope());
        $this->assertSame([], $gql->calls);
    }

    public function testASchemaWithoutMeMeansWrite(): void
    {
        $gql = new FakeGraphQlGateway(['data' => []], BuildSchema::build('type Query { apiVersion: String! }'));
        $this->assertSame('write', (new ScopeProbe($gql))->scope());
        $this->assertSame([], $gql->calls);
    }

    /** @dataProvider nonAnswers */
    public function testAnythingButAnExplicitWriteMeansRead(array $envelope): void
    {
        $gql = (new FakeGraphQlGateway())->on('me { scope }', $envelope);
        $this->assertSame('read', (new ScopeProbe($gql))->scope());
    }

    public function nonAnswers(): array
    {
        return [
            'graphql error' => [['errors' => [['message' => 'Internal server error']]]],
            'no data' => [[]],
            'null me' => [['data' => ['me' => null]]],
            'missing scope' => [['data' => ['me' => ['login' => 'admin']]]],
            'empty scope' => [['data' => ['me' => ['scope' => '']]]],
            'unknown scope' => [['data' => ['me' => ['scope' => 'admin']]]],
            'non-string scope' => [['data' => ['me' => ['scope' => ['write']]]]],
            'read' => [['data' => ['me' => ['scope' => 'read']]]],
        ];
    }

    public function testAnExplicitWriteMeansWrite(): void
    {
        $this->assertSame('write', (new ScopeProbe(self::gateway('write')))->scope());
    }

    public function testCallingAReadOnlyToolDoesNotProbeTheScope(): void
    {
        $gql = self::gateway('write');
        self::catalog($gql)->callTool('customer_list', []);
        $this->assertCount(1, $gql->calls);
        $this->assertSame([], $gql->callsContaining('me { scope }'));
    }

    public function testCallingAWriteToolProbesTheScope(): void
    {
        $gql = self::gateway('write')->on('customerDelete', ['data' => ['customerDelete' => []]]);
        self::catalog($gql)->callTool('customer_delete', ['id' => ['1']]);
        $this->assertCount(1, $gql->callsContaining('me { scope }'));
        $this->assertCount(1, $gql->callsContaining('customerDelete'));
    }

    public function testAGeneratedToolWinsANameCollisionWithTheEscapeHatch(): void
    {
        $sdl = (string)file_get_contents(dirname(__DIR__, 2) . '/data/schema.graphql');
        $sdl = str_replace('type Query {', "type Query {\n  graphqlQuery: String!", $sdl);
        $gql = new FakeGraphQlGateway(['data' => ['graphqlQuery' => 'x']], BuildSchema::build($sdl));
        $gql->on('me { scope }', ['data' => ['me' => ['scope' => 'write']]]);

        $log = (string)tempnam(sys_get_temp_dir(), 'mcplog');
        $previous = ini_set('error_log', $log);
        try {
            $catalog = self::catalog($gql);
            $names = self::names($catalog->listTools());
            $catalog->listTools();
            $result = $catalog->callTool('graphql_query', []);
        } finally {
            ini_set('error_log', (string)$previous);
        }
        $logged = (string)file_get_contents($log);
        unlink($log);

        $this->assertSame(1, array_count_values($names)['graphql_query']);
        $this->assertSame(
            ['graphql_schema', 'graphql_mutate'],
            array_values(array_intersect($names, ['graphql_schema', 'graphql_mutate']))
        );
        // The generated query tool ran, not the escape hatch (which would need a `query` argument).
        $this->assertFalse($result['isError']);
        $this->assertCount(1, $gql->callsContaining('{ graphqlQuery }'));
        $this->assertSame(1, substr_count($logged, 'graphql_query'));
    }

    public function testScopeProbeCaches(): void
    {
        $gql = self::gateway('read');
        $probe = new ScopeProbe($gql);
        $this->assertSame('read', $probe->scope());
        $this->assertSame('read', $probe->scope());
        $this->assertSame(
            [['document' => '{ me { scope } }', 'variables' => [], 'operationName' => null]],
            $gql->calls
        );
    }

    public function testUnauthenticatedIs401ByDefault(): void
    {
        $gql = FakeGraphQlGateway::failing(new AuthFailure(AuthFailure::UNAUTHENTICATED, 'Token expired.'));
        try {
            self::catalog($gql)->listTools();
            $this->fail('expected HttpStatusException');
        } catch (HttpStatusException $e) {
            $this->assertSame(401, $e->status());
            $this->assertSame(['error' => 'invalid_token', 'message' => 'Token expired.'], $e->body());
        }
    }

    public function testForbiddenIs403ByDefault(): void
    {
        $gql = self::gateway();
        $catalog = self::catalog($gql);
        $catalog->listTools();
        $gql->failWith(new AuthFailure(AuthFailure::FORBIDDEN, 'No API access.'));
        try {
            $catalog->callTool('me', []);
            $this->fail('expected HttpStatusException');
        } catch (HttpStatusException $e) {
            $this->assertSame(403, $e->status());
            $this->assertSame(['error' => 'NO_API_ACCESS', 'message' => 'No API access.'], $e->body());
        }
    }

    public function testAuthFailureGoesThroughTheInjectedMapper(): void
    {
        $gql = FakeGraphQlGateway::failing(new AuthFailure(AuthFailure::UNAUTHENTICATED, 'x'));
        $seen = null;
        $mapper = function (AuthFailure $f) use (&$seen): HttpStatusException {
            $seen = $f->reason();
            return new HttpStatusException(401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
        };
        try {
            self::catalog($gql, true, $mapper)->callTool('me', []);
            $this->fail('expected HttpStatusException');
        } catch (HttpStatusException $e) {
            $this->assertSame(['WWW-Authenticate' => 'Bearer error="invalid_token"'], $e->headers());
            $this->assertSame(AuthFailure::UNAUTHENTICATED, $seen);
        }
    }
}
