<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Http\RequestContext;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

class RequestContextTest extends TestCase
{
    /** @param array<string, string> $server */
    private function request(array $server, array $headers = []): ServerRequestInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest('POST', '/', $server);
        foreach ($headers as $name => $value) {
            $r = $r->withHeader($name, $value);
        }
        return $r;
    }

    public function testPlainHttpIsNotHttps(): void
    {
        $ctx = RequestContext::fromRequest($this->request(['REMOTE_ADDR' => '198.51.100.7']), false);
        $this->assertFalse($ctx->isHttps());
        $this->assertSame('198.51.100.7', $ctx->clientIp());
    }

    public function testHttpsServerParam(): void
    {
        $this->assertTrue(RequestContext::fromRequest($this->request(['HTTPS' => 'on']), false)->isHttps());
        $this->assertFalse(RequestContext::fromRequest($this->request(['HTTPS' => 'off']), false)->isHttps());
        $this->assertFalse(RequestContext::fromRequest($this->request(['HTTPS' => '']), false)->isHttps());
    }

    public function testForwardedHeadersIgnoredWithoutTrustProxy(): void
    {
        $ctx = RequestContext::fromRequest($this->request(
            ['REMOTE_ADDR' => '10.0.0.5'],
            ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9']
        ), false);
        $this->assertFalse($ctx->isHttps());
        $this->assertSame('10.0.0.5', $ctx->clientIp());
    }

    public function testForwardedHeadersBelievedWithTrustProxy(): void
    {
        // As graphql's RequestInfo: the first X-Forwarded-For hop is the client.
        $ctx = RequestContext::fromRequest($this->request(
            ['REMOTE_ADDR' => '10.0.0.5'],
            ['X-Forwarded-Proto' => 'HTTPS', 'X-Forwarded-For' => '203.0.113.9, 10.0.0.7']
        ), true);
        $this->assertTrue($ctx->isHttps());
        $this->assertSame('203.0.113.9', $ctx->clientIp());
    }

    public function testOrigin(): void
    {
        $this->assertNull(RequestContext::fromRequest($this->request([]), false)->origin());
        $this->assertSame(
            'https://claude.ai',
            RequestContext::fromRequest($this->request([], ['Origin' => ' https://claude.ai ']), false)->origin()
        );
    }

    public function testMcpHeadersFromPsr7Headers(): void
    {
        $ctx = RequestContext::fromRequest($this->request([], [
            'MCP-Protocol-Version' => '2025-06-18',
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'customer_list',
            'Authorization' => 'Bearer abc',
            'Content-Type' => 'application/json',
        ]), false);
        $this->assertSame([
            'mcp-protocol-version' => '2025-06-18',
            'mcp-method' => 'tools/call',
            'mcp-name' => 'customer_list',
            'authorization' => 'Bearer abc',
        ], $ctx->mcpHeaders());
    }

    public function testMcpHeadersFromServerParamsWhenHeadersMissing(): void
    {
        $r = (new ServerRequestFactory())->createServerRequest(
            'POST',
            '/',
            ['HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28', 'HTTP_MCP_METHOD' => 'tools/list']
        );
        $ctx = RequestContext::fromRequest($r, false);
        $this->assertSame('2026-07-28', $ctx->mcpHeaders()['mcp-protocol-version']);
        $this->assertSame('tools/list', $ctx->mcpHeaders()['mcp-method']);
    }

    public function testPsr7HeaderWinsOverServerParam(): void
    {
        $ctx = RequestContext::fromRequest(
            $this->request(['HTTP_MCP_METHOD' => 'ping'], ['Mcp-Method' => 'tools/list']),
            false
        );
        $this->assertSame('tools/list', $ctx->mcpHeaders()['mcp-method']);
    }

    /** @dataProvider authorizationServerParams */
    public function testAuthorizationFromServerParamsWhenHeaderMissing(string $param): void
    {
        $ctx = RequestContext::fromRequest($this->request([$param => 'Bearer xyz']), false);
        $this->assertSame('Bearer xyz', $ctx->mcpHeaders()['authorization']);
        $this->assertSame('xyz', $ctx->bearer());
    }

    public function authorizationServerParams(): array
    {
        return [
            'HTTP_AUTHORIZATION' => ['HTTP_AUTHORIZATION'],
            'REDIRECT_HTTP_AUTHORIZATION' => ['REDIRECT_HTTP_AUTHORIZATION'],
        ];
    }

    /** @dataProvider bearers */
    public function testBearer(?string $authorization, ?string $expected): void
    {
        $headers = $authorization === null ? [] : ['Authorization' => $authorization];
        $this->assertSame($expected, RequestContext::fromRequest($this->request([], $headers), false)->bearer());
    }

    public function bearers(): array
    {
        return [
            'none' => [null, null],
            'bearer' => ['Bearer abc.def', 'abc.def'],
            'case-insensitive scheme' => ['bearer abc', 'abc'],
            'trailing space' => ['Bearer abc  ', 'abc'],
            'basic' => ['Basic dXNlcjpwYXNz', null],
            'empty token' => ['Bearer ', null],
            'two tokens' => ['Bearer a b', null],
        ];
    }
}
