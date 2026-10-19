<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Http\Json;
use PHPUnit\Framework\TestCase;

class JsonTest extends TestCase
{
    public function testEncodeKeepsUnicodeAndSlashes(): void
    {
        $this->assertSame('{"d":"müller.de","u":"a/b"}', Json::encode(['d' => 'müller.de', 'u' => 'a/b']));
    }

    public function testEncodeThrowsOnInvalidUtf8(): void
    {
        $this->expectException(\RuntimeException::class);
        Json::encode("\xB1\x31");
    }

    public function testDecodeObject(): void
    {
        $this->assertSame(['a' => 1], Json::decodeObject('{"a":1}'));
        $this->assertNull(Json::decodeObject('[1]'));
        $this->assertNull(Json::decodeObject('nope'));
        $this->assertNull(Json::decodeObject('"s"'));
    }

    public function testIsList(): void
    {
        $this->assertTrue(Json::isList([1, 2]));
        $this->assertTrue(Json::isList([['a' => 1]]));
        $this->assertFalse(Json::isList(['a' => 1]));
        $this->assertFalse(Json::isList([1 => 'a', 0 => 'b']));
        $this->assertFalse(Json::isList([1 => 'a']));
        // {} decodes to an empty array too, so an empty one is not a list.
        $this->assertFalse(Json::isList([]));
    }
}
