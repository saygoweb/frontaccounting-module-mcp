<?php

namespace FA\Mcp\Tool;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * One GeneratedTool per root field of the caller's schema (spec §6.3), so the
 * tools follow the company's active extensions and never drift from graphql.
 */
final class SchemaToolFactory
{
    /** Root fields that are not tools: the token flow is OAuth's, and the version is not the model's business. */
    private const EXCLUDED = ['login', 'tokenRefresh', 'tokenRevoke', 'apiVersion'];

    /** The input type of a list field's `query` argument. */
    private const MANGO = 'MangoInput';

    private int $defaultListLimit;

    public function __construct(int $defaultListLimit)
    {
        $this->defaultListLimit = $defaultListLimit;
    }

    /** @return Tool[] the queries, then the mutations, each sorted by name */
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
        $tools = [];
        foreach ($root->getFields() as $field) {
            if (in_array($field->name, self::EXCLUDED, true)) {
                continue;
            }
            $tools[GeneratedTool::snakeCase($field->name)] = new GeneratedTool(
                $field,
                $operation,
                SelectionBuilder::defaultFor($field->getType()),
                $this->takesMangoQuery($field) ? $this->defaultListLimit : null,
                SelectionBuilder::onRequestFor($field->getType())
            );
        }
        ksort($tools, SORT_STRING);
        return array_values($tools);
    }

    private function takesMangoQuery(FieldDefinition $field): bool
    {
        $query = $field->getArg('query');
        if ($query === null) {
            return false;
        }
        $type = Type::getNamedType($query->getType());
        return $type !== null && $type->name === self::MANGO;
    }
}
