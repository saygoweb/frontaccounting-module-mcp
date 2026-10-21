<?php

namespace FA\Mcp\Tool;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\LeafType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The default selection set of a generated tool (spec §6.3), in declared
 * order: every scalar and enum field of the return type (a list of them too),
 * plus each single object field one level deep with its own scalars only.
 * A list of objects (an invoice's lines, a customer's branches) is left out,
 * as it is what makes a page of rows large; onRequestFor() names those for the
 * tool's description, so a model can select them with `fields`. Fields that
 * need an argument are skipped.
 */
final class SelectionBuilder
{
    /**
     * The selection-set body without braces, e.g. `id name balance { due }`;
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
            } elseif (!self::isList($field->getType())) {
                $nested = self::nested($field);
                if ($nested !== null) {
                    $parts[] = $nested;
                }
            }
        }
        return $parts === [] ? '__typename' : implode(' ', $parts);
    }

    /**
     * The lists of objects defaultFor() leaves out, each with its own scalars,
     * e.g. `['lines { id stockId quantity }']`; [] when there are none.
     *
     * @return string[]
     */
    public static function onRequestFor(Type $returnType): array
    {
        $type = Type::getNamedType($returnType);
        if (!$type instanceof ObjectType && !$type instanceof InterfaceType) {
            return [];
        }
        $parts = [];
        foreach ($type->getFields() as $field) {
            if (self::selectable($field) && self::isList($field->getType())) {
                $nested = self::nested($field);
                if ($nested !== null) {
                    $parts[] = $nested;
                }
            }
        }
        return $parts;
    }

    /** `name { scalar ... }` for an object (or list of objects) field, or null when it has no scalars. */
    private static function nested(FieldDefinition $field): ?string
    {
        $type = Type::getNamedType($field->getType());
        if (!$type instanceof ObjectType && !$type instanceof InterfaceType) {
            return null;
        }
        $leaves = self::leaves($type);
        return $leaves === [] ? null : $field->name . ' { ' . implode(' ', $leaves) . ' }';
    }

    /** Whether $type, under any non-null, is a list. */
    private static function isList(Type $type): bool
    {
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }
        return $type instanceof ListOfType;
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
