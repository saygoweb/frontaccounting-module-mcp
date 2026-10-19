<?php

namespace FA\Mcp\OAuth;

/** The real clock. */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
