<?php

namespace FA\Mcp\Protocol;

/** What the transport must send. body null => no body. */
final class HttpOutcome
{
    private int $status;
    private ?array $body;
    /** @var array<string,string> */
    private array $headers;

    public function __construct(int $status, ?array $body, array $headers = [])
    {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** The JSON body, still to be encoded; null for none (e.g. 202). */
    public function body(): ?array
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
