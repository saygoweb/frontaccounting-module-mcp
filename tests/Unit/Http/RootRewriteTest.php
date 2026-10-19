<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Http\RootRewrite;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class RootRewriteTest extends TestCase
{
    private static function path(string $requestPath, string $basePath, $redirectUrl): string
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $requestPath);
        return RootRewrite::apply($request, $basePath, $redirectUrl)->getUri()->getPath();
    }

    public function testTheRootFormTakesTheRewrittenPath(): void
    {
        $this->assertSame('/modules/mcp/.well-known/oauth-authorization-server', self::path(
            '/.well-known/oauth-authorization-server/modules/mcp',
            '/modules/mcp',
            '/modules/mcp/.well-known/oauth-authorization-server'
        ));
    }

    /** @return array<string, array{0: string, 1: string, 2: mixed}> */
    public function untouched(): array
    {
        return [
            'a path under the module' => ['/modules/mcp/oauth/token', '/modules/mcp', '/modules/mcp/.well-known/x'],
            'the module itself' => ['/modules/mcp', '/modules/mcp', '/modules/mcp/.well-known/x'],
            'no redirect' => ['/.well-known/oauth-authorization-server/modules/mcp', '/modules/mcp', null],
            'a redirect elsewhere' => ['/elsewhere', '/modules/mcp', '/modules/mcp/oauth/token'],
            'a redirect outside the module' => ['/x', '/modules/mcp', '/modules/mcpx/.well-known/y'],
            'no base path' => ['/.well-known/x', '', '/.well-known/x'],
        ];
    }

    /**
     * @dataProvider untouched
     * @param mixed $redirectUrl
     */
    public function testOtherRequestsAreLeftAlone(string $requestPath, string $basePath, $redirectUrl): void
    {
        $this->assertSame($requestPath, self::path($requestPath, $basePath, $redirectUrl));
    }
}
