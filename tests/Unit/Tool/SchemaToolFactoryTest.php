<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\EscapeHatch\GraphQlMutate;
use FA\Mcp\Tool\EscapeHatch\GraphQlQuery;
use FA\Mcp\Tool\EscapeHatch\GraphQlSchema;
use FA\Mcp\Tool\SchemaToolFactory;
use FA\Mcp\Tool\Tool;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;

class SchemaToolFactoryTest extends TestCase
{
    private const FIELDS_DESCRIPTION
        = 'A GraphQL selection set to return instead of the default, e.g. `id name contacts { id email }`. The'
        . ' default is every scalar field and the scalars of each nested object; lists of nested objects (an'
        . ' invoice\'s `lines`, a customer\'s `contacts`) are left out unless selected here.';

    /** @return array<string, Tool> */
    private static function tools(): array
    {
        $byName = [];
        foreach ((new SchemaToolFactory(50))->fromSchema(FakeGraphQlGateway::fixture()) as $tool) {
            $byName[$tool->name()] = $tool;
        }
        return $byName;
    }

    /**
     * What a client sees of requiresWrite(): exactly the tools not annotated
     * readOnlyHint, which a read connection's tools/list leaves out. The http
     * suite relies on it to check that list against the write one.
     */
    public function testReadOnlyHintIsExactlyNotRequiringWrite(): void
    {
        $tools = array_merge(
            array_values(self::tools()),
            [new GraphQlQuery(), new GraphQlMutate(), new GraphQlSchema()]
        );
        foreach ($tools as $tool) {
            $this->assertSame(!$tool->requiresWrite(), $tool->annotations()['readOnlyHint'], $tool->name());
        }
    }

    public function testNamesQueriesThenMutationsEachSorted(): void
    {
        $this->assertSame(
            ['customer_count', 'customer_list', 'me', 'recurring_due_list', 'customer_create', 'customer_delete',
                'customer_update', 'invoice_email'],
            array_keys(self::tools())
        );
    }

    public function testAnnotations(): void
    {
        $tools = self::tools();
        $this->assertTrue($tools['customer_list']->annotations()['readOnlyHint']);
        $this->assertTrue($tools['me']->annotations()['readOnlyHint']);
        $this->assertTrue($tools['recurring_due_list']->annotations()['readOnlyHint']);
        $this->assertTrue($tools['customer_delete']->annotations()['destructiveHint']);
        $this->assertTrue($tools['customer_update']->annotations()['idempotentHint']);
        $this->assertTrue($tools['invoice_email']->annotations()['openWorldHint']);
        $this->assertFalse($tools['customer_create']->annotations()['destructiveHint']);
        $this->assertFalse($tools['customer_create']->annotations()['readOnlyHint']);
        $this->assertFalse($tools['customer_create']->annotations()['openWorldHint']);
        foreach (['customer_create', 'customer_delete', 'customer_update', 'invoice_email'] as $name) {
            $this->assertTrue($tools[$name]->requiresWrite(), $name);
            $this->assertFalse($tools[$name]->annotations()['readOnlyHint'], $name);
        }
        foreach (['customer_count', 'customer_list', 'me', 'recurring_due_list'] as $name) {
            $this->assertFalse($tools[$name]->requiresWrite(), $name);
        }
        foreach ($tools as $name => $tool) {
            $this->assertSame(
                ['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'],
                array_keys($tool->annotations()),
                $name
            );
        }
    }

    public function testFieldsArgument(): void
    {
        $tools = self::tools();
        $this->assertSame(
            ['type' => 'string', 'description' => self::FIELDS_DESCRIPTION],
            $tools['customer_list']->inputSchema()['properties']['fields']
        );
        $this->assertNotContains('fields', $tools['customer_list']->inputSchema()['required'] ?? []);
        // The real argument wins and keeps its own schema.
        $this->assertSame(
            ['type' => ['string', 'null']],
            $tools['invoice_email']->inputSchema()['properties']['fields']
        );
    }

    public function testARealFieldsArgumentWinsOnAnObjectReturn(): void
    {
        $schema = BuildSchema::build('type T { id: ID } type Query { thing(fields: Int): T }');
        $tool = (new SchemaToolFactory(50))->fromSchema($schema)[0];
        $this->assertSame(['type' => ['integer', 'null']], $tool->inputSchema()['properties']['fields']);
    }

    public function testDescriptions(): void
    {
        $tools = self::tools();
        $this->assertSame(
            'Query `customerList` of the FrontAccounting GraphQL API.'
            . "\n\nNot returned unless asked for in `fields`: `contacts { id name email }`.",
            $tools['customer_list']->description()
        );
        $this->assertSame(
            'Mutation `customerCreate` of the FrontAccounting GraphQL API.'
            . "\n\nNot returned unless asked for in `fields`: `contacts { id name email }`.",
            $tools['customer_create']->description()
        );
        $this->assertSame('The signed-in user.', $tools['me']->description());
    }

    public function testTheDescriptionNamesEachListOfObjectsLeftOut(): void
    {
        $schema = BuildSchema::build('
            type L { x: Int } type M { y: Int }
            type T { id: ID ls: [L!]! ms: [M] }
            type Query { """Things.""" things: [T!]! }
        ');
        $tool = (new SchemaToolFactory(50))->fromSchema($schema)[0];
        $this->assertSame(
            "Things.\n\nNot returned unless asked for in `fields`: `ls { x }`, `ms { y }`.",
            $tool->description()
        );
    }

    public function testNoHintWhenFieldsIsTheFieldsOwnArgument(): void
    {
        $schema = BuildSchema::build('
            type L { x: Int } type T { id: ID ls: [L!]! }
            type Query { """Things.""" things(fields: Int): [T!]! }
        ');
        $tool = (new SchemaToolFactory(50))->fromSchema($schema)[0];
        $this->assertSame('Things.', $tool->description());
    }

    public function testTitleAndSchemaShape(): void
    {
        $tools = self::tools();
        $this->assertSame('Customer list', $tools['customer_list']->title());
        $this->assertSame('object', $tools['me']->inputSchema()['type']);
        $this->assertFalse($tools['me']->inputSchema()['additionalProperties']);
        $this->assertSame(['asOf'], $tools['recurring_due_list']->inputSchema()['required']);
    }

    public function testOnlyAMangoFieldThatReturnsAListGetsTheLimit(): void
    {
        $schema = BuildSchema::build('
            input MangoInput { selector: String limit: Int }
            type Query { """Odd.""" oddCount(query: MangoInput): Int! oddList(query: MangoInput): [Int] }
        ');
        $gql = new FakeGraphQlGateway(['data' => ['oddCount' => 3]], $schema);
        $tools = (new SchemaToolFactory(50))->fromSchema($schema);
        $this->assertSame(['odd_count', 'odd_list'], array_map(static function (Tool $tool): string {
            return $tool->name();
        }, $tools));
        $tools[0]->call([], $gql);
        $this->assertSame('query { oddCount }', $gql->calls[0]['document']);
        $tools[1]->call([], $gql);
        $this->assertSame(['query' => ['limit' => 50]], $gql->calls[1]['variables']);
    }

    public function testACountWithoutItsListIsAPlainTool(): void
    {
        $schema = BuildSchema::build('
            input MangoInput { selector: String limit: Int }
            type Query { """Odd.""" oddCount(query: MangoInput): Int! }
        ');
        $tool = (new SchemaToolFactory(50))->fromSchema($schema)[0];
        $this->assertSame('Odd.', $tool->description());
        $this->assertNull($tool->queryableList());
    }

    public function testACountIsDescribedByItsList(): void
    {
        $this->assertSame(
            'How many rows customer_list returns for this `query`: the same selector, scope and access. limit,'
            . ' skip and sort are ignored, so a page\'s query can be passed as it is.',
            self::tools()['customer_count']->description()
        );
        $this->assertSame('Customer count', self::tools()['customer_count']->title());
    }

    public function testSchemaWithoutMutations(): void
    {
        $schema = BuildSchema::build('type Query { salesOrderList: [Int] apiVersion: String }');
        $tools = (new SchemaToolFactory(50))->fromSchema($schema);
        $this->assertCount(1, $tools);
        $this->assertSame('sales_order_list', $tools[0]->name());
    }
}
