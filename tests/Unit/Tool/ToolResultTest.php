<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tool\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
    public function testOkWrapsDataAndPrettyPrintsIt(): void
    {
        $mcp = ToolResult::ok(['url' => 'https://example.com/a', 'name' => 'Müller'])->toMcp();
        $this->assertSame(['content', 'structuredContent', 'isError'], array_keys($mcp));
        $this->assertSame(
            ['data' => ['url' => 'https://example.com/a', 'name' => 'Müller']],
            $mcp['structuredContent']
        );
        $this->assertSame([['type' => 'text', 'text' => json_encode(
            $mcp['structuredContent'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )]], $mcp['content']);
        $this->assertStringContainsString('https://example.com/a', $mcp['content'][0]['text']);
        $this->assertStringContainsString('Müller', $mcp['content'][0]['text']);
        $this->assertFalse($mcp['isError']);
    }

    public function testOkWithNote(): void
    {
        $mcp = ToolResult::ok(['a' => 1], 'see more')->toMcp();
        $this->assertSame(['data' => ['a' => 1], 'note' => 'see more'], $mcp['structuredContent']);
    }

    public function testError(): void
    {
        $result = ToolResult::error('Nope.');
        $mcp = $result->toMcp();
        $this->assertTrue($result->isError());
        $this->assertTrue($mcp['isError']);
        $this->assertSame('Nope.', $mcp['content'][0]['text']);
        $this->assertSame(['error' => 'Nope.'], $mcp['structuredContent']);
    }

    public function testInvalidUtf8IsSubstituted(): void
    {
        $mcp = ToolResult::ok(['name' => "Caf\xE9"])->toMcp();
        $this->assertNotFalse(json_encode($mcp));
        $this->assertStringContainsString("Caf\u{FFFD}", $mcp['content'][0]['text']);
        $this->assertFalse($mcp['isError']);
    }

    public function testGraphQlErrorsBecomeIsErrorWithCodes(): void
    {
        $mcp = ToolResult::fromGraphQl(
            ['errors' => [['message' => 'No.', 'extensions' => ['code' => 'FORBIDDEN']]]]
        )->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertSame('FORBIDDEN: No.', $mcp['content'][0]['text']);
    }

    public function testFaRejectedCarriesFaMessagesAndWarnings(): void
    {
        $mcp = ToolResult::fromGraphQl([
            'errors' => [[
                'message' => 'Rejected.',
                'extensions' => ['code' => 'FA_REJECTED', 'messages' => ['The customer has no branch.']],
            ]],
        ])->toMcp();
        $this->assertSame("FA_REJECTED: Rejected.\n- The customer has no branch.", $mcp['content'][0]['text']);
        $ok = ToolResult::fromGraphQl(
            ['data' => ['x' => 1], 'extensions' => ['warnings' => ['Stock below reorder level.']]]
        )->toMcp();
        $this->assertSame(['x' => 1], $ok['structuredContent']['data']);
        $this->assertSame(['Stock below reorder level.'], $ok['structuredContent']['warnings']);
    }

    public function testAMessageRepeatingTheErrorIsNotListedTwice(): void
    {
        $mcp = ToolResult::fromGraphQl([
            'errors' => [
                ['message' => 'Rejected.', 'extensions' => ['code' => 'FA_REJECTED', 'messages' => ['Rejected.']]],
            ],
        ])->toMcp();
        $this->assertSame('FA_REJECTED: Rejected.', $mcp['content'][0]['text']);
    }

    public function testEachErrorOnItsOwnLineAndPartialDataIsDropped(): void
    {
        $mcp = ToolResult::fromGraphQl([
            'data' => ['a' => 1],
            'errors' => [['message' => 'One.'], ['message' => 'Two.', 'extensions' => ['code' => 'BAD_INPUT']]],
        ])->toMcp();
        $this->assertSame("INTERNAL: One.\nBAD_INPUT: Two.", $mcp['content'][0]['text']);
        $this->assertSame(['error' => "INTERNAL: One.\nBAD_INPUT: Two."], $mcp['structuredContent']);
    }

    public function testWarningsAreAppendedToAnError(): void
    {
        $mcp = ToolResult::fromGraphQl([
            'errors' => [['message' => 'No.', 'extensions' => ['code' => 'FORBIDDEN']]],
            'extensions' => ['warnings' => ['Committed earlier.']],
        ])->toMcp();
        $this->assertSame("FORBIDDEN: No.\nWarning: Committed earlier.", $mcp['content'][0]['text']);
    }

    public function testFieldUnwrapsTheRootFieldAndNoteIsAddedLast(): void
    {
        $mcp = ToolResult::fromGraphQl(
            ['data' => ['customerList' => [['id' => '1']]], 'extensions' => ['warnings' => ['W']]],
            'customerList',
            'limit applied'
        )->toMcp();
        $this->assertSame(
            ['data' => [['id' => '1']], 'warnings' => ['W'], 'note' => 'limit applied'],
            $mcp['structuredContent']
        );
    }

    public function testScalarRootFieldIsKept(): void
    {
        $mcp = ToolResult::fromGraphQl(['data' => ['invoiceEmail' => true]], 'invoiceEmail')->toMcp();
        $this->assertSame(['data' => true], $mcp['structuredContent']);
        $this->assertFalse($mcp['isError']);
    }

    public function testNoteIsDroppedOnError(): void
    {
        $mcp = ToolResult::fromGraphQl(['errors' => [['message' => 'x']]], 'customerList', 'limit applied')->toMcp();
        $this->assertArrayNotHasKey('note', $mcp['structuredContent']);
    }
}
