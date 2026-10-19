<?php

namespace FA\Mcp\Tests\Integration;

use FA\GraphQL\Api\Executor;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Gateway\ExecutorAccountGateway;
use PHPUnit\Framework\TestCase;

/**
 * ExecutorAccountGateway over the real Executor the CI image serves (graphql
 * active in company 0), as the CI user `test`: what the OAuth server gets back
 * on success, and how each failure arrives as AuthFailure.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExecutorAccountGatewayTest extends TestCase
{
    private const LOGIN = 'test';
    private const PASSWORD = 'test';
    private const LABEL = 'mcp-account-gateway-test';
    private const NO_COMPANY = 999;

    private ?Executor $executor = null;
    /** @var string[] jtis to revoke in tearDown */
    private array $jtis = [];

    protected function setUp(): void
    {
        if (
            !class_exists(Executor::class)
            || !is_file(self::graphqlConfig())
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

    private static function graphqlConfig(): string
    {
        return dirname(__DIR__, 3) . '/graphql/config_graphql.php';
    }

    private function gateway(): ExecutorAccountGateway
    {
        return new ExecutorAccountGateway($this->executor, '203.0.113.7');
    }

    /**
     * An Executor whose every call fails before it reaches FrontAccounting with
     * something other than ExecutorAuthException.
     */
    private function brokenGateway(): ExecutorAccountGateway
    {
        $executor = new Executor(Config::fromFile(self::graphqlConfig()), static function (): void {
            throw new \RuntimeException('container unavailable');
        });

        return new ExecutorAccountGateway($executor);
    }

    private function assertAuthFailure(string $reason, callable $call): AuthFailure
    {
        try {
            $call();
        } catch (AuthFailure $e) {
            $this->assertSame($reason, $e->reason());
            $this->assertNotNull($e->getPrevious());
            return $e;
        }
        $this->fail('Expected AuthFailure ' . $reason);
    }

    public function testCompaniesListsCompanyZero(): void
    {
        $companies = $this->gateway()->companies();
        $this->assertArrayHasKey(0, $companies);
        $this->assertIsString($companies[0]);
    }

    public function testAuthenticateWithTheRightPasswordReturnsTheStoredLogin(): void
    {
        $result = $this->gateway()->authenticate(0, strtoupper(self::LOGIN), self::PASSWORD);
        $this->assertSame(['status' => 'OK', 'login' => self::LOGIN], $result);
    }

    public function testAuthenticateWithAWrongPasswordIsBadCredentials(): void
    {
        $result = $this->gateway()->authenticate(0, self::LOGIN, 'not-the-password');
        $this->assertSame('BAD_CREDENTIALS', $result['status']);
    }

    public function testAuthenticateForAnUnknownCompanyIsAuthFailure(): void
    {
        $this->assertAuthFailure(AuthFailure::UNAUTHENTICATED, function (): void {
            $this->gateway()->authenticate(self::NO_COMPANY, self::LOGIN, self::PASSWORD);
        });
    }

    public function testIssuedTokenIsLiveUntilRevoked(): void
    {
        $gateway = $this->gateway();
        $issued = $gateway->issueToken(0, self::LOGIN, 'read', 600, self::LABEL);
        $this->jtis[] = $issued['jti'];

        $this->assertNotSame('', $issued['token']);
        $this->assertGreaterThan(time(), $issued['expiresAt']);
        $this->assertFalse($gateway->tokenIsRevoked(0, $issued['jti']));

        $this->assertTrue($gateway->revokeToken(0, $issued['jti']));
        $this->assertTrue($gateway->tokenIsRevoked(0, $issued['jti']));
        $this->assertFalse($gateway->revokeToken(0, $issued['jti']), 'already revoked');
    }

    public function testIssueTokenForAnUnknownUserIsAuthFailure(): void
    {
        $this->assertAuthFailure(AuthFailure::UNAUTHENTICATED, function (): void {
            $this->gateway()->issueToken(0, 'no-such-user', 'read', 600, self::LABEL);
        });
    }

    public function testTokenIsRevokedForAnUnknownCompanyIsAuthFailure(): void
    {
        $this->assertAuthFailure(AuthFailure::UNAUTHENTICATED, function (): void {
            $this->gateway()->tokenIsRevoked(self::NO_COMPANY, str_repeat('0', 32));
        });
    }

    public function testRevokeTokenForAnUnknownCompanyIsAuthFailure(): void
    {
        $this->assertAuthFailure(AuthFailure::UNAUTHENTICATED, function (): void {
            $this->gateway()->revokeToken(self::NO_COMPANY, str_repeat('0', 32));
        });
    }

    public function testAnyOtherFailureOfTheRevocationCallsIsUnauthenticated(): void
    {
        $gateway = $this->brokenGateway();
        $failure = $this->assertAuthFailure(AuthFailure::UNAUTHENTICATED, function () use ($gateway): void {
            $gateway->tokenIsRevoked(0, str_repeat('0', 32));
        });
        $this->assertSame('container unavailable', $failure->getMessage());

        $this->assertAuthFailure(AuthFailure::UNAUTHENTICATED, function () use ($gateway): void {
            $gateway->revokeToken(0, str_repeat('0', 32));
        });
    }
}
