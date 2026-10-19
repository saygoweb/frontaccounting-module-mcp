<?php

namespace FA\Mcp\Http;

final class Json
{
    /**
     * @param mixed $value
     * @throws \RuntimeException when the value cannot be encoded
     */
    public static function encode($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Whether a decoded array was a JSON array rather than an object: keys
     * 0..n-1 in order. An empty array is NOT a list, because {} decodes to
     * one too and an empty params or arguments object is the common case;
     * a caller for which [] must count as a list says so itself.
     */
    public static function isList(array $a): bool
    {
        return $a !== [] && array_keys($a) === range(0, count($a) - 1);
    }

    /** The decoded JSON object as an array, or null for invalid JSON or a non-object. */
    public static function decodeObject(string $s): ?array
    {
        $trimmed = ltrim($s);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return null;
        }
        $value = json_decode($s, true);
        return is_array($value) ? $value : null;
    }
}
