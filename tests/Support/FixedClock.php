<?php

namespace FA\Mcp\Tests\Support;

use FA\Mcp\OAuth\Clock;

/** A clock a test sets and moves by hand. */
final class FixedClock implements Clock
{
    private int $now;

    public function __construct(int $now = 1700000000)
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $now): void
    {
        $this->now = $now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
