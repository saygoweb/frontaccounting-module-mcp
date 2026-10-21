<?php

namespace FA\Mcp\Tests\Unit\Protocol;

use FA\Mcp\Protocol\Instructions;
use PHPUnit\Framework\TestCase;

class InstructionsTest extends TestCase
{
    public function testSaysHowToSortNewestFirst(): void
    {
        $this->assertStringContainsString('`sort: ["-date"]`', Instructions::TEXT);
    }

    public function testSaysWhereTheQueryableFieldsAreNamed(): void
    {
        $this->assertStringContainsString("description names the fields", Instructions::TEXT);
        $this->assertStringContainsString('BAD_INPUT', Instructions::TEXT);
    }
}
