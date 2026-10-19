<?php

namespace FA\Mcp\Tool;

use GraphQL\Type\Definition\Argument;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;

/**
 * A GraphQL field's arguments as the JSON Schema of a tool's input (spec §6.3):
 * String and ID are strings, Int an integer, Float a number, Boolean a boolean,
 * a custom scalar a string with the scalar's description, an enum a string
 * `enum`, an input object a closed object (nesting capped at MAX_DEPTH), a list
 * an array; a non-null without a default is `required`. Descriptions of
 * arguments, fields and types are carried over.
 */
final class JsonSchemaMapper
{
    /** The input-object nesting level that is no longer expanded: `{"type": "object"}` only. */
    public const MAX_DEPTH = 6;

    private const MANGO = 'MangoInput';
    private const SELECTOR_DESCRIPTION = 'A Mango selector: a JSON object, or the same object JSON-encoded.';

    /**
     * @param string[] $omit argument names to leave out
     * @return array an object schema; empty properties are a \stdClass
     */
    public static function forArguments(FieldDefinition $field, array $omit = []): array
    {
        $properties = [];
        $required = [];
        foreach ($field->args as $arg) {
            if (in_array($arg->name, $omit, true)) {
                continue;
            }
            $properties[$arg->name] = self::withDescription(self::forType($arg->getType(), 0), $arg->description);
            if (self::isRequired($arg)) {
                $required[] = $arg->name;
            }
        }
        return self::object($properties, $required);
    }

    /** @param Argument|InputObjectField $value */
    private static function isRequired($value): bool
    {
        return $value->getType() instanceof NonNull && !$value->defaultValueExists();
    }

    /** @param int $depth the input-object nesting level of the type's container */
    private static function forType(Type $type, int $depth): array
    {
        if ($type instanceof NonNull) {
            return self::forType($type->getWrappedType(), $depth);
        }
        if ($type instanceof ListOfType) {
            return ['type' => 'array', 'items' => self::forType($type->getWrappedType(), $depth)];
        }
        if ($type instanceof EnumType) {
            $schema = ['type' => 'string', 'enum' => array_map(function ($value): string {
                return $value->name;
            }, $type->getValues())];
            return self::withDescription($schema, $type->description);
        }
        if ($type instanceof InputObjectType) {
            return self::forInputObject($type, $depth + 1);
        }
        if ($type instanceof ScalarType) {
            return self::forScalar($type);
        }
        return ['type' => 'string'];
    }

    private static function forScalar(ScalarType $type): array
    {
        switch ($type->name) {
            case Type::INT:
                return ['type' => 'integer'];
            case Type::FLOAT:
                return ['type' => 'number'];
            case Type::BOOLEAN:
                return ['type' => 'boolean'];
            case Type::STRING:
            case Type::ID:
                return ['type' => 'string'];
            default:
                return self::withDescription(['type' => 'string'], $type->description);
        }
    }

    private static function forInputObject(InputObjectType $type, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['type' => 'object'];
        }
        $properties = [];
        $required = [];
        foreach ($type->getFields() as $name => $field) {
            if ($type->name === self::MANGO && $name === 'selector') {
                // GeneratedTool JSON-encodes an object selector, so a model may pass either.
                $properties[$name] = ['type' => ['string', 'object'], 'description' => self::SELECTOR_DESCRIPTION];
            } else {
                $schema = self::forType($field->getType(), $depth);
                $properties[$name] = self::withDescription($schema, $field->description);
            }
            if (self::isRequired($field)) {
                $required[] = $name;
            }
        }
        $schema = self::withDescription(['type' => 'object'], $type->description);
        return $schema + self::object($properties, $required);
    }

    /**
     * @param array<string, array> $properties
     * @param string[] $required
     */
    private static function object(array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        $schema['additionalProperties'] = false;
        return $schema;
    }

    /** $schema with $description set, the more specific one replacing a type's. */
    private static function withDescription(array $schema, ?string $description): array
    {
        if ($description !== null && $description !== '') {
            $schema['description'] = $description;
        }
        return $schema;
    }
}
