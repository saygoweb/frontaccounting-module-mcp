<?php

namespace FA\Mcp\Tool;

use FA\Mcp\Http\Json;

/**
 * Checks tool arguments against the small subset of JSON Schema the tools'
 * inputSchema() use, before any GraphQL runs (spec §6.1).
 *
 * Supported: `type` (a name, or a list of names that may include "null"),
 * `properties`, `required`, `additionalProperties: false`, `items`, `enum`,
 * `minLength`, `minimum` and `maximum`. Anything else in a schema is ignored,
 * so a schema may still carry `description` for the model's benefit.
 *
 * Values are what json_decode(..., true) produced, which shapes two rules:
 * an integer is a PHP int only (5.0 decodes to a float and is refused, as
 * "5" is), and JSON's {} and [] both arrive as an empty array, so an empty
 * array satisfies either `object` or `array`.
 */
final class InputValidator
{
    /**
     * @param mixed $value
     * @return string|null null if valid, else one message naming the JSON
     *                     path, e.g. "/input/quota: expected integer"
     */
    public static function validate(array $schema, $value, string $path = ''): ?string
    {
        $at = $path === '' ? '/' : $path;

        if (isset($schema['type'])) {
            $types = (array)$schema['type'];
            $matched = false;
            foreach ($types as $type) {
                if (self::isType($value, $type)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return $at . ': expected ' . implode(' or ', $types);
            }
            if ($value === null) {
                return null;
            }
        }

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            return $at . ': must be one of ' . implode(', ', array_map('json_encode', $schema['enum']));
        }

        if (is_string($value) && isset($schema['minLength'])) {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($length < $schema['minLength']) {
                return $at . ': must be at least ' . $schema['minLength'] . ' characters';
            }
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return $at . ': must be at least ' . $schema['minimum'];
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return $at . ': must be at most ' . $schema['maximum'];
            }
        }

        if (is_array($value) && Json::isList($value) && isset($schema['items'])) {
            foreach ($value as $i => $item) {
                $error = self::validate($schema['items'], $item, $path . '/' . $i);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        if (is_array($value) && !Json::isList($value)) {
            return self::validateObject($schema, $value, $path);
        }

        return null;
    }

    /** properties, required and additionalProperties, for a value already known to be an object. */
    private static function validateObject(array $schema, array $value, string $path): ?string
    {
        $properties = isset($schema['properties']) ? (array)$schema['properties'] : [];

        foreach (isset($schema['required']) ? (array)$schema['required'] : [] as $name) {
            if (!array_key_exists($name, $value)) {
                return $path . '/' . $name . ': is required';
            }
        }

        foreach ($value as $name => $item) {
            if (isset($properties[$name])) {
                $error = self::validate($properties[$name], $item, $path . '/' . $name);
                if ($error !== null) {
                    return $error;
                }
            } elseif (isset($schema['additionalProperties']) && $schema['additionalProperties'] === false) {
                return $path . '/' . $name . ': unexpected property';
            }
        }

        return null;
    }

    /** @param mixed $value */
    private static function isType($value, string $type): bool
    {
        switch ($type) {
            case 'null':
                return $value === null;
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'array':
                // [] and {} decode alike, so an empty array satisfies both
                // "array" and "object": the schema, not the bytes, decides.
                return is_array($value) && ($value === [] || Json::isList($value));
            case 'object':
                return is_array($value) && !Json::isList($value);
            default:
                return false;
        }
    }
}
