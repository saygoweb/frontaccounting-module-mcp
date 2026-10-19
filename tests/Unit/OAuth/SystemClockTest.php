<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\OAuth\SystemClock;
use PHPUnit\Framework\TestCase;

class SystemClockTest extends TestCase
{
    public function testNowIsTheCurrentUnixTime(): void
    {
        $before = time();
        $now = (new SystemClock())->now();
        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual(time(), $now);
    }
}
