<?php

namespace FA\Mcp\Tool;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\LeafType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The default selection set of a generated tool (spec §6.3): every scalar and
 * enum field of the return type, plus each object (or list of objects) field
 * one level deep with its own scalars only, in declared order. Fields that
 * need an argument are skipped.
 */
final class SelectionBuilder
{
    /**
     * The selection-set body without braces, e.g. `id name contacts { id }`;
     * '' for a scalar or enum return, which takes no selection set, and
     * `__typename` when nothing else can be selected.
     */
    public static function defaultFor(Type $returnType): string
    {
        $type = Type::getNamedType($returnType);
        if ($type instanceof LeafType) {
            return '';
        }
        if (!$type instanceof ObjectType && !$type instanceof InterfaceType) {
            return '__typename';
        }

        $parts = [];
        foreach ($type->getFields() as $field) {
            if (!self::selectable($field)) {
                continue;
            }
            $fieldType = Type::getNamedType($field->getType());
            if ($fieldType instanceof LeafType) {
                $parts[] = $field->name;
            } elseif ($fieldType instanceof ObjectType || $fieldType instanceof InterfaceType) {
                $nested = self::leaves($fieldType);
                if ($nested !== []) {
                    $parts[] = $field->name . ' { ' . implode(' ', $nested) . ' }';
                }
            }
        }
        return $parts === [] ? '__typename' : implode(' ', $parts);
    }

    /**
     * @param ObjectType|InterfaceType $type
     * @return string[] the names of $type's selectable scalar and enum fields
     */
    private static function leaves($type): array
    {
        $names = [];
        foreach ($type->getFields() as $field) {
            if (self::selectable($field) && Type::getNamedType($field->getType()) instanceof LeafType) {
                $names[] = $field->name;
            }
        }
        return $names;
    }

    /** False when the field has an argument it cannot be selected without. */
    private static function selectable(FieldDefinition $field): bool
    {
        foreach ($field->args as $arg) {
            if ($arg->getType() instanceof NonNull && !$arg->defaultValueExists()) {
                return false;
            }
        }
        return true;
    }
}
