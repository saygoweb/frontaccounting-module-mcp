<?php

namespace FA\Mcp\Protocol;

/**
 * Thrown by a ToolProvider to make the transport answer with an HTTP status
 * (401/403) instead of a JSON-RPC body - the challenge an OAuth client needs
 * to see at the HTTP layer.
 */
final class HttpStatusException extends \RuntimeException
{
    private int $status;
    /** @var array<string,string> */
    private array $headers;
    private ?array $body;

    public function __construct(int $status, array $headers = [], ?array $body = null)
    {
        parent::__construct('HTTP ' . $status, $status);
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): ?array
    {
        return $this->body;
    }
}
