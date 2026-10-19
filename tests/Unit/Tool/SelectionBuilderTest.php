<?php

namespace FA\Mcp\Tests\Unit\Tool;

use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\SelectionBuilder;
use GraphQL\Type\Definition\Type;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;

class SelectionBuilderTest extends TestCase
{
    private static function returnType(string $field, ?string $sdl = null): Type
    {
        $schema = $sdl === null ? FakeGraphQlGateway::fixture() : BuildSchema::build($sdl);
        $query = $schema->getQueryType();
        $mutation = $schema->getMutationType();
        if ($query !== null && $query->hasField($field)) {
            return $query->getField($field)->getType();
        }
        assert($mutation !== null);
        return $mutation->getField($field)->getType();
    }

    public function testCustomerListInDeclaredOrder(): void
    {
        $this->assertSame(
            'id name currency creditStatus balance contacts { id name email }',
            SelectionBuilder::defaultFor(self::returnType('customerList'))
        );
    }

    public function testViewer(): void
    {
        $this->assertSame('login areas scope', SelectionBuilder::defaultFor(self::returnType('me')));
    }

    public function testScalarReturnHasNoSelection(): void
    {
        $this->assertSame('', SelectionBuilder::defaultFor(self::returnType('invoiceEmail')));
        $this->assertSame('', SelectionBuilder::defaultFor(Type::nonNull(Type::boolean())));
    }

    public function testOnlyNestedObjectsFallsBackToTypename(): void
    {
        $sdl = 'type A { b: B } type B { a: A } type Query { a: A }';
        $this->assertSame('__typename', SelectionBuilder::defaultFor(self::returnType('a', $sdl)));
    }

    public function testNestedObjectsAreOneLevelDeepAndFieldsNeedingArgumentsAreSkipped(): void
    {
        $sdl = '
            type Leaf { x: Int }
            type Mid { y: Int leaf: Leaf }
            type Top { id: ID mid: Mid p(n: Int!): Int q(n: Int): Int mids: [Mid!]! }
            type Query { top: Top }
        ';
        $this->assertSame('id mid { y } q mids { y }', SelectionBuilder::defaultFor(self::returnType('top', $sdl)));
    }

    public function testUnionReturnSelectsTypename(): void
    {
        $sdl = 'type A { x: Int } type B { y: Int } union U = A | B type Query { u: U }';
        $this->assertSame('__typename', SelectionBuilder::defaultFor(self::returnType('u', $sdl)));
    }
}
