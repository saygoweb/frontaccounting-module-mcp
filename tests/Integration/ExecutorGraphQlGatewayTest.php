<?php

namespace FA\Mcp\Tests\Integration;

use FA\GraphQL\Api\Executor;
use FA\GraphQL\Fa\Bootstrap;
use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Gateway\ExecutorGraphQlGateway;
use PHPUnit\Framework\TestCase;

/**
 * ExecutorGraphQlGateway over the real Executor the CI image serves, as the CI
 * user `test`: documents run as the bearer, the schema is fetched once, and a
 * token the Executor refuses arrives as AuthFailure from either call.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExecutorGraphQlGatewayTest extends TestCase
{
    private const LOGIN = 'test';
    private const LABEL = 'mcp-graphql-gateway-test';

    private ?Executor $executor = null;
    /** @var string[] jtis to revoke in tearDown */
    private array $jtis = [];

    protected function setUp(): void
    {
        if (
            !class_exists(Executor::class)
            || !is_file(dirname(__DIR__, 3) . '/graphql/config_graphql.php')
            || !is_file(Bootstrap::defaultRoot() . '/config_db.php')
        ) {
            $this->markTestSkipped('Needs FrontAccounting and the GraphQL module: run in the FA CI image.');
        }
        $this->executor = Executor::create();
    }

    protected function tearDown(): void
    {
        if ($this->executor === null) {
            return;
        }
        foreach ($this->jtis as $jti) {
            $this->executor->revokeToken(0, $jti);
        }
    }

    private function gateway(string $scope = 'read'): ExecutorGraphQlGateway
    {
        $issued = $this->executor->issueToken(0, self::LOGIN, $scope, 600, self::LABEL);
        $this->jtis[] = $issued['jti'];

        return new ExecutorGraphQlGateway($this->executor, $issued['token'], '203.0.113.7');
    }

    public function testExecuteRunsAsTheBearer(): void
    {
        $out = $this->gateway('read')->execute('query Me { me { login scope } }', [], 'Me');
        $this->assertSame(['login' => self::LOGIN, 'scope' => 'read'], $out['data']['me']);
    }

    public function testExecutePassesVariables(): void
    {
        $out = $this->gateway()->execute(
            'query ($q: MangoInput) { customerList(query: $q) { id } }',
            ['q' => ['limit' => 1]]
        );
        $this->assertArrayNotHasKey('errors', $out);
        $this->assertLessThanOrEqual(1, count($out['data']['customerList']));
    }

    public function testTheSchemaIsFetchedOnce(): void
    {
        $gateway = $this->gateway();
        $schema = $gateway->schema();
        $this->assertNotNull($schema->getQueryType()->getField('customerList'));
        $this->assertSame($schema, $gateway->schema());
    }

    public function testARefusedTokenIsAuthFailureFromExecute(): void
    {
        $gateway = new ExecutorGraphQlGateway($this->executor, 'not-a-token', '203.0.113.7');
        try {
            $gateway->execute('{ me { login } }');
            $this->fail('Expected AuthFailure');
        } catch (AuthFailure $e) {
            $this->assertSame(AuthFailure::UNAUTHENTICATED, $e->reason());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function testARefusedTokenIsAuthFailureFromSchema(): void
    {
        $gateway = new ExecutorGraphQlGateway($this->executor, 'not-a-token', '203.0.113.7');
        try {
            $gateway->schema();
            $this->fail('Expected AuthFailure');
        } catch (AuthFailure $e) {
            $this->assertSame(AuthFailure::UNAUTHENTICATED, $e->reason());
        }
    }
}
