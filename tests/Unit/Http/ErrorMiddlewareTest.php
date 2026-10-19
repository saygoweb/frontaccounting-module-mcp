<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Http\ErrorMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class ErrorMiddlewareTest extends TestCase
{
    private ServerRequestInterface $request;

    protected function setUp(): void
    {
        $this->request = (new ServerRequestFactory())->createServerRequest('POST', '/modules/mcp/');
        ini_set('error_log', '/dev/null');
    }

    /** @param callable(ServerRequestInterface): ResponseInterface $handle */
    private function process(bool $debug, callable $handle): ResponseInterface
    {
        $handler = new class ($handle) implements RequestHandlerInterface {
            /** @var callable */
            private $handle;

            public function __construct(callable $handle)
            {
                $this->handle = $handle;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)($request);
            }
        };

        return (new ErrorMiddleware($debug))->process($this->request, $handler);
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    public function testAResponseFromTheHandlerPassesThrough(): void
    {
        $expected = (new Response())->withStatus(202);
        $this->assertSame($expected, $this->process(false, function () use ($expected): ResponseInterface {
            return $expected;
        }));
    }

    public function testNotFoundKeepsItsStatusAsJson(): void
    {
        $response = $this->process(false, function (ServerRequestInterface $request): ResponseInterface {
            throw new HttpNotFoundException($request);
        });
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $body = self::body($response);
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertNull($body['id']);
        $this->assertSame(-32600, $body['error']['code']);
    }

    public function testMethodNotAllowedSaysWhichMethodsAre(): void
    {
        $response = $this->process(false, function (ServerRequestInterface $request): ResponseInterface {
            $e = new HttpMethodNotAllowedException($request);
            $e->setAllowedMethods(['POST', 'OPTIONS']);
            throw $e;
        });
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST, OPTIONS', $response->getHeaderLine('Allow'));
        $this->assertSame(-32600, self::body($response)['error']['code']);
    }

    public function testAnythingElseIsAnInternalErrorWithoutItsMessage(): void
    {
        $response = $this->process(false, function (): ResponseInterface {
            throw new \RuntimeException('secret detail');
        });
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            ['code' => -32603, 'message' => 'Internal error'],
            self::body($response)['error']
        );
    }

    public function testDebugAddsTheMessage(): void
    {
        $response = $this->process(true, function (): ResponseInterface {
            throw new \LogicException('secret detail');
        });
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal error: secret detail', self::body($response)['error']['message']);
    }
}
