<?php

namespace FA\Mcp\Tests\Unit\Runtime;

use FA\Mcp\Runtime\GraphQlRequirement;
use PHPUnit\Framework\TestCase;

class GraphQlRequirementTest extends TestCase
{
    private const MESSAGE = 'FrontAccounting MCP needs the GraphQL module (Executor contract 1.x), active.';

    /** An Executor double's class name: anonymous, with the given CONTRACT_VERSION. */
    private static function executor(string $version): string
    {
        switch ($version) {
            case '1.3':
                return get_class(new class {
                    public const CONTRACT_VERSION = '1.3';
                });
            case '2.0':
                return get_class(new class {
                    public const CONTRACT_VERSION = '2.0';
                });
            default:
                return get_class(new class {
                });
        }
    }

    public function testMissingClass(): void
    {
        $requirement = new GraphQlRequirement('\\FA\\Mcp\\Tests\\Unit\\Runtime\\NoSuchExecutor');
        $this->assertSame(self::MESSAGE, $requirement->check());
    }

    public function testOtherMajor(): void
    {
        $this->assertSame(self::MESSAGE, (new GraphQlRequirement(self::executor('2.0')))->check());
    }

    public function testNoContractConstant(): void
    {
        $this->assertSame(self::MESSAGE, (new GraphQlRequirement(self::executor('none')))->check());
    }

    public function testMajorOne(): void
    {
        $this->assertNull((new GraphQlRequirement(self::executor('1.3')))->check());
    }

    public function testDefaultNamesGraphQlsExecutor(): void
    {
        // The unit suite may or may not have graphql beside it; either way the answer follows.
        $expected = class_exists('FA\\GraphQL\\Api\\Executor') ? null : self::MESSAGE;
        $this->assertSame($expected, (new GraphQlRequirement())->check());
    }
}
