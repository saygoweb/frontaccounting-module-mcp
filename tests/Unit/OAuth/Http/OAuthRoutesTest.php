<?php

namespace FA\Mcp\Tests\Unit\OAuth\Http;

use DI\Container;
use FA\Mcp\Config;
use FA\Mcp\Http\ErrorMiddleware;
use FA\Mcp\Http\McpAction;
use FA\Mcp\OAuth\Http\AuthorizeAction;
use FA\Mcp\OAuth\Http\MetadataAction;
use FA\Mcp\OAuth\Http\RegisterAction;
use FA\Mcp\OAuth\Http\TokenAction;
use FA\Mcp\OAuth\Metadata;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * app.php's routes (spec §5.1), each action replaced by a stub that names
 * itself, so only the routing is under test.
 */
class OAuthRoutesTest extends TestCase
{
    private const BASE_PATH = '/modules/mcp';

    /** @param array<string, mixed> $config */
    private static function app(array $config): \Slim\App
    {
        $config = Config::fromArray($config + ['require_tls' => false]);
        $container = new Container();
        $container->set(Config::class, $config);
        $container->set(ErrorMiddleware::class, new ErrorMiddleware(false));
        $container->set(MetadataAction::class, new MetadataAction(
            $config,
            new Metadata($config->baseUrl, $config->allowDcr, $config->allowCimd)
        ));
        foreach (
            [
                McpAction::class => 'mcp',
                AuthorizeAction::class => 'authorize',
                TokenAction::class => 'token',
                RegisterAction::class => 'register',
            ] as $class => $name
        ) {
            // Not static: Slim binds a closure it resolves to the container.
            $container->set($class, \DI\value(
                function (ServerRequestInterface $req, ResponseInterface $res) use ($name): ResponseInterface {
                    $res->getBody()->write($name);
                    return $res->withStatus(299);
                }
            ));
        }
        return (require dirname(__DIR__, 4) . '/app.php')($container, self::BASE_PATH);
    }

    private static function send(\Slim\App $app, string $method, string $path): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, self::BASE_PATH . $path);
        return $app->handle($request);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public function routes(): array
    {
        return [
            'protected resource' => ['GET', '/.well-known/oauth-protected-resource', 'resource'],
            'authorization server' => ['GET', '/.well-known/oauth-authorization-server', 'issuer'],
            'openid configuration' => ['GET', '/.well-known/openid-configuration', 'issuer'],
            'authorize GET' => ['GET', '/oauth/authorize', 'authorize'],
            'authorize POST' => ['POST', '/oauth/authorize', 'authorize'],
            'token POST' => ['POST', '/oauth/token', 'token'],
            'token OPTIONS' => ['OPTIONS', '/oauth/token', 'token'],
            'register POST' => ['POST', '/oauth/register', 'register'],
            'register OPTIONS' => ['OPTIONS', '/oauth/register', 'register'],
        ];
    }

    /** @dataProvider routes */
    public function testEachRouteReachesItsAction(string $method, string $path, string $marker): void
    {
        $app = self::app(['base_url' => 'http://localhost/modules/mcp']);
        $body = (string) self::send($app, $method, $path)->getBody();
        $this->assertStringContainsString($marker, $body);
    }

    /** @dataProvider routes */
    public function testNothingIsServedWithoutBaseUrl(string $method, string $path): void
    {
        $this->assertSame(404, self::send(self::app([]), $method, $path)->getStatusCode());
    }

    /** @dataProvider routes */
    public function testNothingIsServedWithOAuthOff(string $method, string $path): void
    {
        $app = self::app(['base_url' => 'http://localhost/modules/mcp', 'oauth' => false]);
        $this->assertSame(404, self::send($app, $method, $path)->getStatusCode());
    }

    public function testRegisterIsNotServedWithoutDcr(): void
    {
        $app = self::app(['base_url' => 'http://localhost/modules/mcp', 'allow_dcr' => false]);
        $this->assertSame(404, self::send($app, 'POST', '/oauth/register')->getStatusCode());
        $this->assertSame(404, self::send($app, 'OPTIONS', '/oauth/register')->getStatusCode());
        $this->assertSame(299, self::send($app, 'POST', '/oauth/token')->getStatusCode());
    }

    public function testTheMetadataDocumentsAreTheSame(): void
    {
        $app = self::app(['base_url' => 'http://localhost/modules/mcp']);
        $this->assertSame(
            (string) self::send($app, 'GET', '/.well-known/oauth-authorization-server')->getBody(),
            (string) self::send($app, 'GET', '/.well-known/openid-configuration')->getBody()
        );
    }

    public function testTheMcpEndpointIsStillServed(): void
    {
        $this->assertSame('mcp', (string) self::send(self::app([]), 'POST', '/')->getBody());
    }
}
