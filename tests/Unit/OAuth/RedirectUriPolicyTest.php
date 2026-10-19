<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\OAuth\RedirectUriPolicy;
use PHPUnit\Framework\TestCase;

class RedirectUriPolicyTest extends TestCase
{
    private const CLAUDE = 'https://claude.ai/api/mcp/auth_callback';

    public function testLoopbackRedirectIgnoresPort(): void
    {
        $this->assertTrue(
            RedirectUriPolicy::matches('http://localhost:53122/callback', ['http://localhost:8765/callback'])
        );
        $this->assertTrue(RedirectUriPolicy::matches('http://127.0.0.1:1/cb', ['http://127.0.0.1/cb']));
        $this->assertFalse(RedirectUriPolicy::matches('http://localhost:1/other', ['http://localhost:2/callback']));
    }

    public function testLoopbackHostMustStillMatch(): void
    {
        $this->assertFalse(RedirectUriPolicy::matches('http://127.0.0.1:1/cb', ['http://localhost:2/cb']));
    }

    public function testNonLoopbackPortMustMatch(): void
    {
        $this->assertFalse(RedirectUriPolicy::matches('https://claude.ai:444/api/mcp/auth_callback', [self::CLAUDE]));
        $this->assertTrue(RedirectUriPolicy::matches(self::CLAUDE, [self::CLAUDE]));
    }

    public function testHttpsIsExactMatch(): void
    {
        $this->assertFalse(RedirectUriPolicy::matches(self::CLAUDE . '?x=1', [self::CLAUDE]));
        $this->assertFalse(RedirectUriPolicy::matches('https://claude.ai/api/mcp/auth_callback', []));
    }

    public function testAcceptable(): void
    {
        $this->assertTrue(RedirectUriPolicy::isAcceptable('https://claude.ai/api/mcp/auth_callback'));
        $this->assertTrue(RedirectUriPolicy::isAcceptable('http://localhost:8765/callback'));
        $this->assertTrue(RedirectUriPolicy::isAcceptable('http://127.0.0.1/cb'));
        $this->assertTrue(RedirectUriPolicy::isAcceptable('http://[::1]:9/cb'));
        $this->assertFalse(RedirectUriPolicy::isAcceptable('http://evil.example/cb'));
        $this->assertFalse(RedirectUriPolicy::isAcceptable('https://x/cb#f'));
        $this->assertFalse(RedirectUriPolicy::isAcceptable('javascript:alert(1)'));
        $this->assertFalse(RedirectUriPolicy::isAcceptable('https://user:pw@x.example/cb'));
        $this->assertFalse(RedirectUriPolicy::isAcceptable(''));
    }
}
