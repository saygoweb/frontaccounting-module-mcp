<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\InputValidator;
use FA\Mcp\Tool\JsonSchemaMapper;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;

class JsonSchemaMapperTest extends TestCase
{
    private static function field(string $name, ?Schema $schema = null): FieldDefinition
    {
        $schema = $schema ?? FakeGraphQlGateway::fixture();
        $query = $schema->getQueryType();
        $mutation = $schema->getMutationType();
        if ($query !== null && $query->hasField($name)) {
            return $query->getField($name);
        }
        assert($mutation !== null);
        return $mutation->getField($name);
    }

    public function testCustomerList(): void
    {
        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => ['object', 'null'],
                    'description' => 'Mango query: selector is a JSON-encoded Mango selector string.',
                    'properties' => [
                        'action' => ['type' => ['string', 'null']],
                        'selector' => [
                            'type' => ['string', 'object', 'null'],
                            'description' => 'A Mango selector: a JSON object, or the same object JSON-encoded.',
                        ],
                        'limit' => ['type' => ['integer', 'null']],
                        'skip' => ['type' => ['integer', 'null']],
                        'sort' => ['type' => ['array', 'null'], 'items' => ['type' => ['string', 'null']]],
                    ],
                    'additionalProperties' => false,
                ],
            ],
            'additionalProperties' => false,
        ], JsonSchemaMapper::forArguments(self::field('customerList')));
    }

    public function testCustomerCreate(): void
    {
        $schema = JsonSchemaMapper::forArguments(self::field('customerCreate'));
        $this->assertSame(['input'], $schema['required']);
        $input = $schema['properties']['input'];
        $this->assertSame('array', $input['type']);
        $this->assertSame('object', $input['items']['type']);
        $this->assertContains('name', $input['items']['required']);
        $this->assertNotContains('currencyId', $input['items']['required']);
        $this->assertSame(
            ['type' => ['string', 'null'], 'enum' => ['GOOD', 'HOLD', null]],
            $input['items']['properties']['creditStatus']
        );
        $this->assertFalse($input['items']['additionalProperties']);
    }

    public function testNoArgumentsEncodeAsAnEmptyObject(): void
    {
        $schema = JsonSchemaMapper::forArguments(self::field('me'));
        $this->assertStringContainsString('"properties":{}', (string)json_encode($schema));
        $this->assertArrayNotHasKey('required', $schema);
    }

    public function testOmit(): void
    {
        $schema = JsonSchemaMapper::forArguments(self::field('invoiceEmail'), ['fields']);
        $this->assertSame(['id'], array_keys($schema['properties']));
    }

    public function testNonNullWithADefaultIsNotRequired(): void
    {
        $schema = BuildSchema::build('type Query { a(n: Int! = 3, m: Int!): Int }');
        $this->assertSame(['m'], JsonSchemaMapper::forArguments(self::field('a', $schema))['required']);
    }

    public function testRecursionStopsAtDepthSix(): void
    {
        $schema = BuildSchema::build('input F { and: [F!] x: Int } type Query { q(f: F): Int }');
        $node = JsonSchemaMapper::forArguments(self::field('q', $schema))['properties']['f'];
        for ($depth = 1; $depth < 6; $depth++) {
            $this->assertSame(['type' => ['integer', 'null']], $node['properties']['x'], "depth $depth");
            $node = $node['properties']['and']['items'];
        }
        $this->assertSame(['type' => 'object'], $node);
    }

    public function testScalars(): void
    {
        $schema = BuildSchema::build('
            """An ISO 8601 date."""
            scalar Date
            type Query { a(id: ID, s: String, i: Int, f: Float, b: Boolean, d: Date, l: [[Int!]]): Int }
        ');
        $this->assertSame([
            'id' => ['type' => ['string', 'null']],
            's' => ['type' => ['string', 'null']],
            'i' => ['type' => ['integer', 'null']],
            'f' => ['type' => ['number', 'null']],
            'b' => ['type' => ['boolean', 'null']],
            'd' => ['type' => ['string', 'null'], 'description' => 'An ISO 8601 date.'],
            'l' => [
                'type' => ['array', 'null'],
                'items' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
            ],
        ], JsonSchemaMapper::forArguments(self::field('a', $schema))['properties']);
    }

    public function testArgumentAndFieldDescriptionsAreCarriedOver(): void
    {
        $schema = BuildSchema::build('
            """The type."""
            input In { """The field.""" x: Int }
            type Query { a("""The argument.""" i: In, """Plain.""" s: String): Int }
        ');
        $properties = JsonSchemaMapper::forArguments(self::field('a', $schema))['properties'];
        $this->assertSame('The argument.', $properties['i']['description']);
        $this->assertSame(
            ['type' => ['integer', 'null'], 'description' => 'The field.'],
            $properties['i']['properties']['x']
        );
        $this->assertSame(['type' => ['string', 'null'], 'description' => 'Plain.'], $properties['s']);
    }

    public function testNonNullStaysStrict(): void
    {
        $schema = BuildSchema::build('
            enum E { A B }
            input In { x: Int! }
            type Query { a(i: Int!, e: E!, o: In!, l: [Int!]!): Int }
        ');
        $this->assertSame([
            'i' => ['type' => 'integer'],
            'e' => ['type' => 'string', 'enum' => ['A', 'B']],
            'o' => [
                'type' => 'object',
                'properties' => ['x' => ['type' => 'integer']],
                'required' => ['x'],
                'additionalProperties' => false,
            ],
            'l' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ], JsonSchemaMapper::forArguments(self::field('a', $schema))['properties']);
    }

    public function testExplicitNullIsValidWhereGraphQlAcceptsIt(): void
    {
        $schema = BuildSchema::build('
            enum E { A B }
            input In { x: Int, y: Int!, e: E }
            type Query { a(i: Int, e: E, o: In, l: [Int], n: Int!): Int }
        ');
        $input = JsonSchemaMapper::forArguments(self::field('a', $schema));
        $this->assertNull(InputValidator::validate($input, [
            'i' => null, 'e' => null, 'o' => ['x' => null, 'y' => 1, 'e' => null], 'l' => [null, 2], 'n' => 1,
        ]));
        $this->assertNull(InputValidator::validate($input, ['o' => null, 'n' => 1]));
        $this->assertSame('/n: expected integer', InputValidator::validate($input, ['n' => null]));
        $this->assertSame('/o/y: expected integer', InputValidator::validate($input, ['o' => ['y' => null], 'n' => 1]));
    }
}
