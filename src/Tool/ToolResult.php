<?php

namespace FA\Mcp\Tool;

/**
 * What a tool call produced, in MCP's CallToolResult shape.
 *
 * The same data travels twice: as pretty-printed JSON text for clients that
 * read only `content`, and as `structuredContent` for those that read JSON.
 * MCP requires structuredContent to be a JSON object, so a success is
 * {"data": <value>} plus "warnings" (FrontAccounting's, about work that
 * committed) and "note" when there are any, and a failure is {"error": <text>}.
 */
final class ToolResult
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    private array $structured;
    private string $text;
    private bool $isError;

    private function __construct(array $structured, string $text, bool $isError)
    {
        $this->structured = $structured;
        $this->text = $text;
        $this->isError = $isError;
    }

    /** A successful result: {"data": $data}, plus the note if any. */
    public static function ok(array $data, ?string $note = null): self
    {
        return self::success($data, [], $note);
    }

    /** A tool-level failure the model should read: isError, with $text as the content. */
    public static function error(string $text): self
    {
        return new self(['error' => $text], $text, true);
    }

    /**
     * Map a GraphQL envelope.
     *
     * Any error makes the whole result an error, each one as "<CODE>: <message>"
     * on its own line, followed by its extensions.messages (FA_REJECTED's
     * FrontAccounting messages) as "- " lines: partial data next to an error is
     * dropped, since a model reading half an answer as the whole one is worse
     * than no answer. FrontAccounting's warnings (extensions.warnings) are
     * appended to an error as "Warning: " lines, and kept beside the data of a
     * success.
     *
     * @param string|null $field the root field to unwrap from data, or null for all of data
     * @param string|null $note added to a success only
     */
    public static function fromGraphQl(array $envelope, ?string $field = null, ?string $note = null): self
    {
        $warnings = [];
        if (isset($envelope['extensions']['warnings']) && is_array($envelope['extensions']['warnings'])) {
            $warnings = array_values(array_map('strval', $envelope['extensions']['warnings']));
        }

        if (!empty($envelope['errors'])) {
            $lines = [];
            foreach ((array)$envelope['errors'] as $error) {
                $code = isset($error['extensions']['code']) ? (string)$error['extensions']['code'] : 'INTERNAL';
                $message = isset($error['message']) ? (string)$error['message'] : 'Unknown error';
                $lines[] = $code . ': ' . $message;
                $messages = $error['extensions']['messages'] ?? [];
                foreach (is_array($messages) ? $messages : [] as $faMessage) {
                    if ((string)$faMessage !== $message) {
                        $lines[] = '- ' . $faMessage;
                    }
                }
            }
            foreach ($warnings as $warning) {
                $lines[] = 'Warning: ' . $warning;
            }
            return self::error(implode("\n", $lines));
        }

        $data = isset($envelope['data']) && is_array($envelope['data']) ? $envelope['data'] : [];
        if ($field !== null) {
            $data = $data[$field] ?? null;
        }
        return self::success($data, $warnings, $note);
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    /** The MCP CallToolResult. */
    public function toMcp(): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $this->text]],
            'structuredContent' => $this->structured,
            'isError' => $this->isError,
        ];
    }

    /**
     * @param mixed $data
     * @param string[] $warnings
     */
    private static function success($data, array $warnings, ?string $note): self
    {
        $structured = ['data' => self::substituted($data)];
        if ($warnings !== []) {
            $structured['warnings'] = self::substituted($warnings);
        }
        if ($note !== null) {
            $structured['note'] = $note;
        }
        return new self($structured, self::pretty($structured), false);
    }

    /**
     * The text is read by a model, so indentation helps, and Unicode and
     * slashes stay literal for the same reason. Invalid UTF-8 (FrontAccounting
     * stores Latin-1 in places) becomes U+FFFD rather than failing the call.
     */
    private static function pretty(array $data): string
    {
        $json = json_encode($data, self::JSON_FLAGS);
        if ($json === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * $value with invalid UTF-8 replaced, so structuredContent encodes later
     * whatever flags the transport uses.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function substituted($value)
    {
        if (is_string($value)) {
            return preg_match('//u', $value) === 1
                ? $value
                : json_decode((string)json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[is_string($key) ? self::substituted($key) : $key] = self::substituted($item);
            }
            return $out;
        }
        return $value;
    }
}
