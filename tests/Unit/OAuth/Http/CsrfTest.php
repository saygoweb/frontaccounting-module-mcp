<?php

namespace FA\Mcp\Tests\Unit\OAuth\Http;

use FA\Mcp\OAuth\Http\Csrf;
use FA\Mcp\OAuth\Http\McpSession;
use FA\Mcp\Tests\Support\FixedClock;
use FA\Mcp\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    private static function session(): McpSession
    {
        return McpSession::fromRequest(
            Requests::get('/oauth/authorize'),
            McpSession::key(str_repeat('s', 32)),
            '/oauth/',
            true,
            new FixedClock()
        );
    }

    public function testTokenIsStoredInTheSessionAndStable(): void
    {
        $session = self::session();
        $csrf = new Csrf($session);
        $token = $csrf->token();

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, $session->csrf());
        $this->assertSame($token, $csrf->token());
    }

    public function testValidOnlyForTheSessionToken(): void
    {
        $csrf = new Csrf(self::session());
        $token = $csrf->token();

        $this->assertTrue($csrf->isValid($token));
        $this->assertFalse($csrf->isValid('x' . $token));
        $this->assertFalse($csrf->isValid(''));
        $this->assertFalse($csrf->isValid(null));
        $this->assertFalse($csrf->isValid([$token]));
    }

    public function testNothingIsValidBeforeATokenExists(): void
    {
        $session = self::session();
        $csrf = new Csrf($session);
        $this->assertFalse($csrf->isValid(''));
        $this->assertNull($session->csrf());
    }

    public function testDifferentSessionsGetDifferentTokens(): void
    {
        $this->assertNotSame((new Csrf(self::session()))->token(), (new Csrf(self::session()))->token());
    }
}
