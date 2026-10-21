<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\GeneratedTool;
use FA\Mcp\Tool\SchemaToolFactory;
use PHPUnit\Framework\TestCase;

class GeneratedToolTest extends TestCase
{
    private function tool(string $name): GeneratedTool
    {
        foreach ((new SchemaToolFactory(50))->fromSchema(FakeGraphQlGateway::fixture()) as $tool) {
            if ($tool->name() === $name) {
                $this->assertInstanceOf(GeneratedTool::class, $tool);
                return $tool;
            }
        }
        $this->fail("no tool $name");
    }

    public function testDocumentAndVariables(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => [['id' => '1', 'name' => 'A']]]]);
        $tool = $this->tool('customer_list');
        $tool->call(['query' => ['limit' => 5]], $gql);
        $this->assertSame(
            'query ($query: MangoInput) { customerList(query: $query) '
            . '{ id name currency creditStatus balance } }',
            $gql->calls[0]['document']
        );
        $this->assertSame(['query' => ['limit' => 5]], $gql->calls[0]['variables']);
    }

    public function testAnExplicitNullForANullableArgumentIsPassedThrough(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['invoiceEmail' => true]]);
        $this->tool('invoice_email')->call(['id' => ['7'], 'fields' => null], $gql);
        $this->assertSame(
            'mutation ($id: [ID!]!, $fields: String) { invoiceEmail(id: $id, fields: $fields) }',
            $gql->calls[0]['document']
        );
        $this->assertSame(['id' => ['7'], 'fields' => null], $gql->calls[0]['variables']);
    }

    public function testNullsInsideTheMangoQueryAreKeptAndTheLimitStillApplies(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['query' => ['selector' => null, 'limit' => null, 'skip' => null]], $gql);
        $this->assertSame(
            ['query' => ['selector' => null, 'limit' => 50, 'skip' => null]],
            $gql->calls[0]['variables']
        );

        // A null query is no query: the default limit is still injected.
        $this->tool('customer_list')->call(['query' => null], $gql);
        $this->assertSame(['query' => ['limit' => 50]], $gql->calls[1]['variables']);
    }

    public function testDataIsTheRootFieldsValue(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => [['id' => '1', 'name' => 'A']]]]);
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 5]], $gql)->toMcp();
        $this->assertSame(['data' => [['id' => '1', 'name' => 'A']]], $mcp['structuredContent']);
        $this->assertFalse($mcp['isError']);
    }

    public function testObjectSelectorIsEncoded(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(
            ['query' => ['selector' => ['name' => ['$regex' => 'Acme']], 'limit' => 5]],
            $gql
        );
        $this->assertSame('{"name":{"$regex":"Acme"}}', $gql->calls[0]['variables']['query']['selector']);
    }

    public function testStringSelectorIsPassedAsIs(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['query' => ['selector' => '{"id":"1"}', 'limit' => 5]], $gql);
        $this->assertSame('{"id":"1"}', $gql->calls[0]['variables']['query']['selector']);
    }

    public function testDefaultLimitInjectedAndNoted(): void
    {
        $rows = array_fill(0, 50, ['id' => '1', 'name' => 'A']);
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => $rows]]);
        $mcp = $this->tool('customer_list')->call([], $gql)->toMcp();
        $this->assertSame(50, $gql->calls[0]['variables']['query']['limit']);
        $this->assertStringStartsWith(
            'query ($query: MangoInput) { customerList(query: $query) {',
            $gql->calls[0]['document']
        );
        $this->assertSame(
            'limit applied: 50; pass query.limit or query.skip for more',
            $mcp['structuredContent']['note']
        );
    }

    public function testLimitInjectedBesideOtherQueryKeys(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['query' => ['skip' => 50]], $gql);
        $this->assertSame(['skip' => 50, 'limit' => 50], $gql->calls[0]['variables']['query']);
    }

    public function testNoNoteWhenFewerRowsOrExplicitLimit(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => [['id' => '1', 'name' => 'A']]]]);
        $this->assertArrayNotHasKey('note', $this->tool('customer_list')->call([], $gql)->toMcp()['structuredContent']);

        $rows = array_fill(0, 50, ['id' => '1', 'name' => 'A']);
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => $rows]]);
        $mcp = $this->tool('customer_list')->call(['query' => ['limit' => 50]], $gql)->toMcp();
        $this->assertArrayNotHasKey('note', $mcp['structuredContent']);
    }

    public function testFieldsOverrideSelection(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['fields' => 'id contacts { email }'], $gql);
        $this->assertStringContainsString(
            'customerList(query: $query) { id contacts { email } }',
            $gql->calls[0]['document']
        );
        $this->assertArrayNotHasKey('fields', $gql->calls[0]['variables']);
    }

    public function testFieldsCannotAddAnOperation(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(
            ['fields' => 'id } } mutation { customerDelete(id: ["1"]) { id'],
            $gql
        )->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertStringStartsWith('fields: ', $mcp['content'][0]['text']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsCannotAddARootMutation(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerUpdate' => []]]);
        $mcp = $this->tool('customer_update')->call(
            ['input' => [['id' => '1', 'name' => 'N']], 'fields' => 'id } customerDelete(id: ["1"]) { id'],
            $gql
        )->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertStringStartsWith('fields: ', $mcp['content'][0]['text']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsCannotAddARootQuery(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['fields' => 'id } me { login'], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertStringStartsWith('fields: ', $mcp['content'][0]['text']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsCannotAliasTheRootField(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['fields' => 'id } other: customerList { id'], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsCannotSpreadAFragmentAtTheRoot(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['fields' => 'id } ... on Query { me { login }'], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertSame([], $gql->calls);
    }

    public function testFieldsSyntaxErrorIsAToolError(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $mcp = $this->tool('customer_list')->call(['fields' => 'id {'], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertStringStartsWith('fields: GraphQL syntax error: ', $mcp['content'][0]['text']);
        $this->assertSame([], $gql->calls);
    }

    public function testMutationDocument(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerCreate' => [['id' => '9', 'name' => 'N']]]]);
        $this->tool('customer_create')->call(['input' => [['name' => 'N']]], $gql);
        $this->assertStringStartsWith(
            'mutation ($input: [CustomerCreateInput!]!) { customerCreate(input: $input) { id name',
            $gql->calls[0]['document']
        );
    }

    public function testScalarReturnHasNoSelection(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['invoiceEmail' => true]]);
        $this->tool('invoice_email')->call(['id' => ['3']], $gql);
        $this->assertSame('mutation ($id: [ID!]!) { invoiceEmail(id: $id) }', $gql->calls[0]['document']);
        $this->assertSame(['id' => ['3']], $gql->calls[0]['variables']);
    }

    public function testARealFieldsArgumentIsSentAsAVariable(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['invoiceEmail' => true]]);
        $this->tool('invoice_email')->call(['id' => ['3'], 'fields' => 'x'], $gql);
        $this->assertSame(
            'mutation ($id: [ID!]!, $fields: String) { invoiceEmail(id: $id, fields: $fields) }',
            $gql->calls[0]['document']
        );
        $this->assertSame(['id' => ['3'], 'fields' => 'x'], $gql->calls[0]['variables']);
    }

    public function testNoArguments(): void
    {
        $this->assertSame('query { me { login areas scope } }', $this->tool('me')->document([]));
    }

    public function testDocumentIsPublicAndMatchesTheCall(): void
    {
        $gql = new FakeGraphQlGateway(['data' => ['customerList' => []]]);
        $this->tool('customer_list')->call(['fields' => 'id'], $gql);
        $this->assertSame($this->tool('customer_list')->document(['fields' => 'id']), $gql->calls[0]['document']);
    }

    public function testGraphQlErrorsBecomeAToolError(): void
    {
        $gql = new FakeGraphQlGateway(['errors' => [['message' => 'No.', 'extensions' => ['code' => 'FORBIDDEN']]]]);
        $mcp = $this->tool('customer_list')->call([], $gql)->toMcp();
        $this->assertTrue($mcp['isError']);
        $this->assertSame('FORBIDDEN: No.', $mcp['content'][0]['text']);
    }

    public function testAuthFailurePropagates(): void
    {
        $this->expectException(AuthFailure::class);
        $this->tool('me')->call(
            [],
            FakeGraphQlGateway::failing(new AuthFailure(AuthFailure::UNAUTHENTICATED, 'expired'))
        );
    }
}
