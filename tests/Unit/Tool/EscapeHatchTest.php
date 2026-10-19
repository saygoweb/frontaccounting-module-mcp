<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\EscapeHatch\GraphQlMutate;
use FA\Mcp\Tool\EscapeHatch\GraphQlQuery;
use FA\Mcp\Tool\EscapeHatch\GraphQlSchema;
use FA\Mcp\Tool\InputValidator;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaPrinter;
use PHPUnit\Framework\TestCase;

class EscapeHatchTest extends TestCase
{
    private FakeGraphQlGateway $gql;

    protected function setUp(): void
    {
        $this->gql = new FakeGraphQlGateway(['data' => ['a' => 1]], BuildSchema::build('type Query { a: Int }'));
    }

    private function query(string $document): array
    {
        return (new GraphQlQuery())->call(['query' => $document], $this->gql)->toMcp();
    }

    public function testQueryRejectsAMutationWithoutCallingTheGateway(): void
    {
        $result = $this->query('mutation { x }');
        $this->assertTrue($result['isError']);
        $this->assertSame(
            'graphql_query runs queries only; use graphql_mutate for mutations',
            $result['content'][0]['text']
        );
        $this->assertSame([], $this->gql->calls);
    }

    public function testQueryRejectsASubscription(): void
    {
        $this->assertTrue($this->query('subscription { x }')['isError']);
        $this->assertSame([], $this->gql->calls);
    }

    public function testQueryRunsAQuery(): void
    {
        $result = (new GraphQlQuery())->call(
            ['query' => 'query Q($v: Int) { a }', 'variables' => ['v' => 1], 'operationName' => 'Q'],
            $this->gql
        )->toMcp();
        $this->assertFalse($result['isError']);
        $this->assertSame(['data' => ['a' => 1]], $result['structuredContent']);
        $this->assertSame(
            [['document' => 'query Q($v: Int) { a }', 'variables' => ['v' => 1], 'operationName' => 'Q']],
            $this->gql->calls
        );
    }

    public function testQueryRunsShorthandQuery(): void
    {
        $this->assertFalse($this->query('{ a }')['isError']);
        $this->assertCount(1, $this->gql->calls);
    }

    public function testQueryRejectsAMutationAlongsideAQuery(): void
    {
        $this->assertTrue($this->query('{ a } mutation M { b }')['isError']);
        $this->assertSame([], $this->gql->calls);
    }

    public function testQueryReportsASyntaxError(): void
    {
        $result = $this->query('query {');
        $this->assertTrue($result['isError']);
        $this->assertStringStartsWith('GraphQL syntax error: ', $result['content'][0]['text']);
        $this->assertSame([], $this->gql->calls);
    }

    public function testQueryPassesGraphQlErrorsThrough(): void
    {
        $gql = new FakeGraphQlGateway(['errors' => [[
            'message' => 'too deep', 'extensions' => ['code' => 'QUERY_TOO_COMPLEX'],
        ]]]);
        $result = (new GraphQlQuery())->call(['query' => '{ a }'], $gql)->toMcp();
        $this->assertTrue($result['isError']);
        $this->assertSame('QUERY_TOO_COMPLEX: too deep', $result['content'][0]['text']);
    }

    public function testMutateExecutesTheDocumentAsGiven(): void
    {
        $tool = new GraphQlMutate();
        $result = $tool->call(['mutation' => 'mutation { b }'], $this->gql)->toMcp();
        $this->assertFalse($result['isError']);
        $this->assertSame('mutation { b }', $this->gql->calls[0]['document']);
        $this->assertSame([], $this->gql->calls[0]['variables']);
        $this->assertNull($this->gql->calls[0]['operationName']);
        $this->assertTrue($tool->requiresWrite());
        $this->assertTrue($tool->annotations()['destructiveHint']);
        $this->assertFalse($tool->annotations()['readOnlyHint']);
    }

    public function testSchemaReturnsTheSdl(): void
    {
        $result = (new GraphQlSchema())->call([], $this->gql)->toMcp();
        $this->assertFalse($result['isError']);
        $this->assertSame(
            ['data' => ['sdl' => SchemaPrinter::doPrint($this->gql->schema())]],
            $result['structuredContent']
        );
        $this->assertStringContainsString('type Query', $result['structuredContent']['data']['sdl']);
    }

    public function testReadToolsAreReadOnlyAndNotWrite(): void
    {
        foreach ([new GraphQlSchema(), new GraphQlQuery()] as $tool) {
            $this->assertTrue($tool->annotations()['readOnlyHint'], $tool->name());
            $this->assertFalse($tool->requiresWrite(), $tool->name());
        }
    }

    public function testSchemas(): void
    {
        $this->assertStringContainsString('"properties":{}', (string)json_encode((new GraphQlSchema())->inputSchema()));
        $this->assertNotNull(InputValidator::validate((new GraphQlQuery())->inputSchema(), []));
        $this->assertNotNull(InputValidator::validate((new GraphQlMutate())->inputSchema(), ['mutation' => '']));
        $this->assertNull(InputValidator::validate(
            (new GraphQlMutate())->inputSchema(),
            ['mutation' => 'mutation { b }', 'variables' => ['x' => 1], 'operationName' => 'M']
        ));
    }
}
