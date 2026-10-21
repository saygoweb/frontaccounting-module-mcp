<?php

namespace FA\Mcp\Tool;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * One GeneratedTool per root field of the caller's schema (spec §6.3), so the
 * tools follow the company's active extensions and never drift from graphql.
 *
 * A list is a field that takes a Mango `query` and returns a list; it gets the
 * default limit. `<entity>Count`, a Mango field returning Int beside a list
 * `<entity>List`, is that list's count: the list says a full page's total by
 * it, and the count tool is described by the list.
 */
final class SchemaToolFactory
{
    /** Root fields that are not tools: the token flow is OAuth's, and the version is not the model's business. */
    private const EXCLUDED = ['login', 'tokenRefresh', 'tokenRevoke', 'apiVersion'];

    private int $defaultListLimit;

    public function __construct(int $defaultListLimit)
    {
        $this->defaultListLimit = $defaultListLimit;
    }

    /** @return GeneratedTool[] the queries, then the mutations, each sorted by name */
    public function fromSchema(Schema $schema): array
    {
        return array_merge(
            $this->fromRoot($schema->getQueryType(), GeneratedTool::QUERY),
            $this->fromRoot($schema->getMutationType(), GeneratedTool::MUTATION)
        );
    }

    /** @return GeneratedTool[] */
    private function fromRoot(?ObjectType $root, string $operation): array
    {
        if ($root === null) {
            return [];
        }
        /** @var array<string, GeneratedTool> $tools field name => tool */
        $tools = [];
        foreach ($root->getFields() as $field) {
            if (in_array($field->name, self::EXCLUDED, true)) {
                continue;
            }
            $tools[$field->name] = new GeneratedTool(
                $field,
                $operation,
                SelectionBuilder::defaultFor($field->getType()),
                self::isList($field) ? $this->defaultListLimit : null,
                SelectionBuilder::onRequestFor($field->getType())
            );
        }
        foreach ($root->getFields() as $field) {
            $list = substr($field->name, 0, -strlen('Count')) . 'List';
            if (
                isset($tools[$field->name], $tools[$list])
                && substr($field->name, -strlen('Count')) === 'Count'
                && self::isCount($field)
                && self::isList($root->getField($list))
            ) {
                $tools[$list] = $tools[$list]->withCountField($field);
                $tools[$field->name] = $tools[$field->name]->asCountOf($root->getField($list));
            }
        }
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name()] = $tool;
        }
        ksort($byName, SORT_STRING);
        return array_values($byName);
    }

    /** A Mango `query` field that returns a list. */
    private static function isList(FieldDefinition $field): bool
    {
        return GeneratedTool::takesMangoQuery($field)
            && Type::getNullableType($field->getType()) instanceof ListOfType;
    }

    /** A Mango `query` field that returns an Int. */
    private static function isCount(FieldDefinition $field): bool
    {
        $type = Type::getNullableType($field->getType());
        return GeneratedTool::takesMangoQuery($field) && $type instanceof ScalarType && $type->name === Type::INT;
    }
}
