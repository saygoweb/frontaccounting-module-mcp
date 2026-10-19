<?php

namespace FA\Mcp\Protocol;

/** One validated JSON-RPC request or notification. */
final class Envelope
{
    private bool $notification;
    /** @var string|int|null */
    private $id;
    private string $method;
    private array $params;

    /** @param string|int|null $id */
    private function __construct(bool $notification, $id, string $method, array $params)
    {
        $this->notification = $notification;
        $this->id = $id;
        $this->method = $method;
        $this->params = $params;
    }

    /**
     * @param array $message the decoded JSON object
     * @throws RpcError INVALID_REQUEST for a response, or anything not a well-formed request
     */
    public static function fromMessage(array $message): self
    {
        if (
            !array_key_exists('method', $message)
            && (array_key_exists('result', $message) || array_key_exists('error', $message))
        ) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'JSON-RPC responses are not accepted');
        }
        if (!isset($message['jsonrpc']) || $message['jsonrpc'] !== JsonRpc::VERSION) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'jsonrpc must be "2.0"');
        }
        if (!isset($message['method']) || !is_string($message['method'])) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'method must be a string');
        }
        $notification = !array_key_exists('id', $message);
        if (!$notification && !self::isValidId($message['id'])) {
            throw new RpcError(RpcError::INVALID_REQUEST, 'id must be a string or number');
        }
        $params = [];
        if (array_key_exists('params', $message)) {
            if (!is_array($message['params'])) {
                throw new RpcError(RpcError::INVALID_REQUEST, 'params must be an object');
            }
            $params = $message['params'];
        }
        return new self($notification, $notification ? null : $message['id'], $message['method'], $params);
    }

    /**
     * The id to put on an error reply to a message that may not have parsed
     * as a request: its own id when that is a valid one, otherwise null.
     *
     * @return string|int|null
     */
    public static function replyId(array $message)
    {
        return array_key_exists('id', $message) && self::isValidId($message['id']) ? $message['id'] : null;
    }

    /** @param mixed $id */
    private static function isValidId($id): bool
    {
        return $id === null || is_string($id) || is_int($id) || is_float($id);
    }

    public function isNotification(): bool
    {
        return $this->notification;
    }

    /** @return string|int|null */
    public function id()
    {
        return $this->id;
    }

    public function method(): string
    {
        return $this->method;
    }

    /** The params object as an array; empty when the message had none. */
    public function params(): array
    {
        return $this->params;
    }
}
