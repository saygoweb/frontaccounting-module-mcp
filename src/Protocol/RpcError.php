<?php

namespace FA\Mcp\Protocol;

/**
 * A JSON-RPC error to answer with. The message and data reach the client, so
 * neither may carry anything internal.
 */
final class RpcError extends \RuntimeException
{
    public const PARSE = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL = -32603;
    /** 2026-07-28: an Mcp-* header is missing or disagrees with the body. */
    public const HEADER_MISMATCH = -32020;
    /** 2026-07-28: the _meta protocol version is not one this server speaks. */
    public const UNSUPPORTED_VERSION = -32022;

    /** @var mixed */
    private $data;

    /** @param mixed $data JSON-encodable; null leaves `data` out of the error */
    public function __construct(int $code, string $message, $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }

    public function rpcCode(): int
    {
        return (int)$this->getCode();
    }

    /** @return mixed */
    public function data()
    {
        return $this->data;
    }
}
