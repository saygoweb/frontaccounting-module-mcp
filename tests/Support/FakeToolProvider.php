<?php

namespace FA\Mcp\Tests\Support;

use FA\Mcp\Protocol\ToolProvider;

/**
 * A ToolProvider with a fixed tool list. Each call is recorded and handed to
 * $onCall, whose return value (or exception) is the call's outcome.
 */
class FakeToolProvider implements ToolProvider
{
    /** @var array[] */
    private array $tools;
    /** @var callable */
    private $onCall;
    /** @var array[] each ['name' => string, 'arguments' => array] */
    public array $calls = [];

    public function __construct(array $tools = [], ?callable $onCall = null)
    {
        $this->tools = $tools;
        $this->onCall = $onCall ?: function (string $name, array $arguments): array {
            return ['content' => [['type' => 'text', 'text' => $name]]];
        };
    }

    public function listTools(): array
    {
        return $this->tools;
    }

    public function callTool(string $name, array $arguments): array
    {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments];
        return call_user_func($this->onCall, $name, $arguments);
    }
}
