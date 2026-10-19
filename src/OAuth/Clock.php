<?php

namespace FA\Mcp\OAuth;

/** The time, in Unix seconds. Injected so expiry can be tested without waiting. */
interface Clock
{
    public function now(): int;
}
