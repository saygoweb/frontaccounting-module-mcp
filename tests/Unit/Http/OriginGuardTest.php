<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\OriginGuard;
use PHPUnit\Framework\TestCase;

class OriginGuardTest extends TestCase
{
    private function guard(array $config = []): OriginGuard
    {
        return new OriginGuard(Config::fromArray($config));
    }

    public function testNoOriginIsAllowed(): void
    {
        $this->assertTrue($this->guard()->allows(null));
        $this->assertTrue($this->guard()->allows(''));
        $this->assertFalse($this->guard()->isCorsOrigin(null));
        $this->assertFalse($this->guard()->isCorsOrigin(''));
    }

    public function testDefaultAllowedOriginIsClaudeAi(): void
    {
        $this->assertTrue($this->guard()->allows('https://claude.ai'));
        $this->assertTrue($this->guard()->allows('HTTPS://Claude.AI'));
        $this->assertTrue($this->guard()->isCorsOrigin('https://claude.ai'));
    }

    public function testBaseUrlOriginIsAllowed(): void
    {
        $guard = $this->guard(['base_url' => 'https://fa.example.com/modules/mcp']);
        $this->assertTrue($guard->allows('https://fa.example.com'));
        $this->assertTrue($guard->allows('https://fa.example.com:443'));
        $this->assertFalse($guard->allows('http://fa.example.com'));
        $this->assertFalse($guard->allows('https://fa.example.com:8443'));
    }

    public function testBaseUrlWithPortKeepsThePort(): void
    {
        $guard = $this->guard(['base_url' => 'http://localhost:8080/modules/mcp']);
        $this->assertTrue($guard->allows('http://localhost:8080'));
        $this->assertFalse($guard->allows('http://localhost'));
    }

    public function testOtherOriginsAreRefused(): void
    {
        $guard = $this->guard(['base_url' => 'https://fa.example.com/modules/mcp']);
        $this->assertFalse($guard->allows('https://evil.example'));
        $this->assertFalse($guard->allows('https://fa.example.com.evil.example'));
        $this->assertFalse($guard->allows('https://claude.ai.evil.example'));
        $this->assertFalse($guard->allows('ftp://fa.example.com'));
        $this->assertFalse($guard->allows('https://fa.example.com/path'));
        $this->assertFalse($guard->allows('https://user@fa.example.com'));
        $this->assertFalse($guard->allows('null'));
        $this->assertFalse($guard->isCorsOrigin('https://evil.example'));
    }

    public function testConfiguredOriginsReplaceTheDefault(): void
    {
        $guard = $this->guard(['allowed_origins' => ['https://app.example:8443']]);
        $this->assertTrue($guard->allows('https://app.example:8443'));
        $this->assertFalse($guard->allows('https://claude.ai'));
    }

    public function testWildcardInConfigDoesNotOpenEveryOrigin(): void
    {
        $this->assertFalse($this->guard(['allowed_origins' => ['*']])->allows('https://evil.example'));
    }

    public function testForbiddenBodyIsExact(): void
    {
        $this->assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Origin not allowed"}}',
            json_encode(OriginGuard::FORBIDDEN_BODY)
        );
    }
}
