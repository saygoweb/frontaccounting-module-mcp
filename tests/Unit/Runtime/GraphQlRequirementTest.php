<?php

namespace FA\Mcp\Tests\Unit\Runtime;

use FA\Mcp\Runtime\GraphQlRequirement;
use PHPUnit\Framework\TestCase;

final class ExecutorContract13
{
    public const CONTRACT_VERSION = '1.3';
}

final class ExecutorContract20
{
    public const CONTRACT_VERSION = '2.0';
}

final class ExecutorWithoutContract
{
}

class GraphQlRequirementTest extends TestCase
{
    private const MESSAGE = 'FrontAccounting MCP needs the GraphQL module (Executor contract 1.x), active.';

    public function testMissingClass(): void
    {
        $this->assertSame(self::MESSAGE, (new GraphQlRequirement('\FA\Mcp\Tests\Unit\Runtime\NoSuchExecutor'))->check());
    }

    public function testOtherMajor(): void
    {
        $this->assertSame(self::MESSAGE, (new GraphQlRequirement(ExecutorContract20::class))->check());
    }

    public function testNoContractConstant(): void
    {
        $this->assertSame(self::MESSAGE, (new GraphQlRequirement(ExecutorWithoutContract::class))->check());
    }

    public function testMajorOne(): void
    {
        $this->assertNull((new GraphQlRequirement('\\' . ExecutorContract13::class))->check());
    }

    public function testDefaultNamesGraphQlsExecutor(): void
    {
        // The unit suite may or may not have graphql beside it; either way the answer is consistent.
        $expected = class_exists('FA\GraphQL\Api\Executor') ? null : self::MESSAGE;
        $this->assertSame($expected, (new GraphQlRequirement())->check());
    }
}
