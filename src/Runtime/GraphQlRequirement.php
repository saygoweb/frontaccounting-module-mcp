<?php

namespace FA\Mcp\Runtime;

/**
 * Whether the GraphQL module this one runs on is there and speaks a contract
 * this module was written for (spec §3.2): FA\GraphQL\Api\Executor with a
 * CONTRACT_VERSION of major 1.
 */
final class GraphQlRequirement
{
    public const MESSAGE = 'FrontAccounting MCP needs the GraphQL module (Executor contract 1.x), active.';
    public const MAJOR = 1;

    private string $class;

    /** @param string $class the Executor class; tests name a double */
    public function __construct(string $class = '\FA\GraphQL\Api\Executor')
    {
        $this->class = ltrim($class, '\\');
    }

    /** Null when the requirement is met; otherwise the 503 message. */
    public function check(): ?string
    {
        if (!class_exists($this->class)) {
            return self::MESSAGE;
        }
        $constant = $this->class . '::CONTRACT_VERSION';
        if (!defined($constant)) {
            return self::MESSAGE;
        }
        $version = (string) constant($constant);
        return (int) explode('.', $version)[0] === self::MAJOR ? null : self::MESSAGE;
    }
}
