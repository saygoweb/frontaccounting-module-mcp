<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\SchemaToolFactory;
use FA\Mcp\Tool\Tool;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;

class SchemaToolFactoryTest extends TestCase
{
    private const FIELDS_DESCRIPTION
        = 'A GraphQL selection set to return instead of the default, e.g. `id name branches { branchCode }`.';

    /** @return array<string, Tool> */
    private static function tools(): array
    {
        $byName = [];
        foreach ((new SchemaToolFactory(50))->fromSchema(FakeGraphQlGateway::fixture()) as $tool) {
            $byName[$tool->name()] = $tool;
        }
        return $byName;
    }

    public function testNamesQueriesThenMutationsEachSorted(): void
    {
        $this->assertSame(
            ['customer_list', 'me', 'recurring_due_list', 'customer_create', 'customer_delete', 'customer_update',
                'invoice_email'],
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
        foreach (['customer_list', 'me', 'recurring_due_list'] as $name) {
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
        $this->assertSame(['type' => 'string'], $tools['invoice_email']->inputSchema()['properties']['fields']);
    }

    public function testARealFieldsArgumentWinsOnAnObjectReturn(): void
    {
        $schema = BuildSchema::build('type T { id: ID } type Query { thing(fields: Int): T }');
        $tool = (new SchemaToolFactory(50))->fromSchema($schema)[0];
        $this->assertSame(['type' => 'integer'], $tool->inputSchema()['properties']['fields']);
    }

    public function testDescriptions(): void
    {
        $tools = self::tools();
        $this->assertSame(
            'Query `customerList` of the FrontAccounting GraphQL API.',
            $tools['customer_list']->description()
        );
        $this->assertSame(
            'Mutation `customerCreate` of the FrontAccounting GraphQL API.',
            $tools['customer_create']->description()
        );
        $this->assertSame('The signed-in user.', $tools['me']->description());
    }

    public function testTitleAndSchemaShape(): void
    {
        $tools = self::tools();
        $this->assertSame('Customer list', $tools['customer_list']->title());
        $this->assertSame('object', $tools['me']->inputSchema()['type']);
        $this->assertFalse($tools['me']->inputSchema()['additionalProperties']);
        $this->assertSame(['asOf'], $tools['recurring_due_list']->inputSchema()['required']);
    }

    public function testSchemaWithoutMutations(): void
    {
        $schema = BuildSchema::build('type Query { salesOrderList: [Int] apiVersion: String }');
        $tools = (new SchemaToolFactory(50))->fromSchema($schema);
        $this->assertCount(1, $tools);
        $this->assertSame('sales_order_list', $tools[0]->name());
    }
}
