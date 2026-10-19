<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Config;
use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Gateway\GraphQlGateway;
use FA\Mcp\Http\McpAction;
use FA\Mcp\Protocol\McpServer;
use FA\Mcp\Tests\Support\FakeGraphQlGateway;
use FA\Mcp\Tool\SchemaToolFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

class McpActionTest extends TestCase
{
    private const BASE = 'https://fa.example.com/modules/mcp';
    private const METADATA = 'https://fa.example.com/modules/mcp/.well-known/oauth-protected-resource';

    /** @var array[] each [bearer, clientIp] the gateway factory was called with */
    private array $made = [];

    private function gateway(): FakeGraphQlGateway
    {
        return (new FakeGraphQlGateway())
            ->on('me { scope }', ['data' => ['me' => ['scope' => 'write']]])
            ->on('customerList', ['data' => ['customerList' => [['id' => '1', 'name' => 'Acme']]]]);
    }

    private function action(array $config = [], ?GraphQlGateway $gql = null, bool $trustProxy = false): McpAction
    {
        $gql = $gql ?? $this->gateway();
        $config = Config::fromArray($config + ['base_url' => self::BASE, 'require_tls' => false]);
        return new McpAction(
            $config,
            new McpServer('frontaccounting-mcp', '1.0.0', 'instructions', false),
            new SchemaToolFactory($config->defaultListLimit),
            function (string $bearer, string $ip) use ($gql): GraphQlGateway {
                $this->made[] = [$bearer, $ip];
                return $gql;
            },
            $trustProxy
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function send(
        McpAction $action,
        ?array $message,
        array $headers = [],
        string $method = 'POST',
        array $server = []
    ): ResponseInterface {
        $request = (new ServerRequestFactory())->createServerRequest(
            $method,
            '/modules/mcp/',
            $server + ['REMOTE_ADDR' => '198.51.100.7']
        );
        if (!array_key_exists('Authorization', $headers)) {
            $headers['Authorization'] = 'Bearer good-token';
        } elseif ($headers['Authorization'] === null) {
            unset($headers['Authorization']);
        }
        $headers += ['Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18'];
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($message !== null) {
            $request = $request->withBody((new StreamFactory())->createStream((string) json_encode($message)));
        }
        return $action($request, new Response());
    }

    private static function body(ResponseInterface $response): ?array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function toolsList(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
    }

    public function testToolsListIsServed(): void
    {
        $response = $this->send($this->action(), self::toolsList());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $names = array_column(self::body($response)['result']['tools'], 'name');
        $this->assertContains('customer_list', $names);
        $this->assertSame([['good-token', '198.51.100.7']], $this->made);
    }

    public function testClientIpFollowsTrustProxy(): void
    {
        $headers = ['X-Forwarded-For' => '203.0.113.9'];
        $this->send($this->action([], null, true), self::toolsList(), $headers);
        $this->send($this->action([], null, false), self::toolsList(), $headers);
        $this->assertSame([['good-token', '203.0.113.9'], ['good-token', '198.51.100.7']], $this->made);
    }

    public function testNoAuthorizationIs401WithResourceMetadata(): void
    {
        $response = $this->send($this->action(), self::toolsList(), ['Authorization' => null]);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer resource_metadata="' . self::METADATA . '"', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame('UNAUTHENTICATED', self::body($response)['error']);
        $this->assertSame([], $this->made, 'no gateway for a request without a token');
    }

    public function testNoAuthorizationWithoutBaseUrlIs401WithRealm(): void
    {
        $response = $this->send($this->action(['base_url' => '']), self::toolsList(), ['Authorization' => null]);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer realm="frontaccounting-mcp"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testNonBearerAuthorizationIs401(): void
    {
        $response = $this->send($this->action(), self::toolsList(), ['Authorization' => 'Basic dXNlcjpwYXNz']);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testAuthorizationFromServerParams(): void
    {
        $response = $this->send(
            $this->action(),
            self::toolsList(),
            ['Authorization' => null],
            'POST',
            ['HTTP_AUTHORIZATION' => 'Bearer from-cgi']
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('from-cgi', $this->made[0][0]);
    }

    public function testUnauthenticatedDuringToolsListIs401InvalidToken(): void
    {
        $gql = FakeGraphQlGateway::failing(new AuthFailure(AuthFailure::UNAUTHENTICATED, 'The token has expired.'));
        $response = $this->send($this->action([], $gql), self::toolsList());
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'Bearer error="invalid_token", resource_metadata="' . self::METADATA . '"',
            $response->getHeaderLine('WWW-Authenticate')
        );
        $this->assertSame(['error' => 'invalid_token', 'message' => 'The token has expired.'], self::body($response));
    }

    public function testUnauthenticatedWithoutBaseUrlNamesTheRealm(): void
    {
        $gql = FakeGraphQlGateway::failing(new AuthFailure(AuthFailure::UNAUTHENTICATED, 'bad'));
        $response = $this->send($this->action(['base_url' => ''], $gql), self::toolsList());
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'Bearer realm="frontaccounting-mcp", error="invalid_token"',
            $response->getHeaderLine('WWW-Authenticate')
        );
    }

    public function testForbiddenIs403NoApiAccess(): void
    {
        $gql = FakeGraphQlGateway::failing(new AuthFailure(AuthFailure::FORBIDDEN, 'No SA_GRAPHQL.'));
        $response = $this->send($this->action([], $gql), self::toolsList());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['error' => 'NO_API_ACCESS', 'message' => 'No SA_GRAPHQL.'], self::body($response));
        $this->assertSame('', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testPlainHttpWithRequireTlsIs403(): void
    {
        $response = $this->send($this->action(['require_tls' => true]), self::toolsList());
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('TLS_REQUIRED', self::body($response)['error']);
        $this->assertSame([], $this->made);
    }

    public function testHttpsWithRequireTlsIsServed(): void
    {
        $response = $this->send($this->action(['require_tls' => true]), self::toolsList(), [], 'POST', ['HTTPS' => 'on']);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testForwardedHttpsWithRequireTlsNeedsTrustProxy(): void
    {
        $headers = ['X-Forwarded-Proto' => 'https'];
        $this->assertSame(
            403,
            $this->send($this->action(['require_tls' => true]), self::toolsList(), $headers)->getStatusCode()
        );
        $this->assertSame(
            200,
            $this->send($this->action(['require_tls' => true], null, true), self::toolsList(), $headers)->getStatusCode()
        );
    }

    public function testPlainHttpWithoutRequireTlsIsServed(): void
    {
        $this->assertSame(200, $this->send($this->action(['require_tls' => false]), self::toolsList())->getStatusCode());
    }

    public function testForeignOriginIs403(): void
    {
        $response = $this->send($this->action(), self::toolsList(), ['Origin' => 'https://evil.example']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(-32600, self::body($response)['error']['code']);
        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame([], $this->made);
    }

    public function testAllowedOriginIsServedWithCors(): void
    {
        $response = $this->send($this->action(), self::toolsList(), ['Origin' => 'https://claude.ai']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('https://claude.ai', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function testCorsHeadersOnA401(): void
    {
        $response = $this->send(
            $this->action(),
            self::toolsList(),
            ['Origin' => 'https://claude.ai', 'Authorization' => null]
        );
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('https://claude.ai', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('WWW-Authenticate', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    public function testBaseUrlOriginIsServed(): void
    {
        $response = $this->send($this->action(), self::toolsList(), ['Origin' => 'https://fa.example.com']);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNotificationIs202WithEmptyBody(): void
    {
        $response = $this->send($this->action(), ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testModernHeadersReachTheProtocol(): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ];
        $ok = $this->send($this->action(), $message, ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list']);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('complete', self::body($ok)['result']['resultType']);

        $mismatch = $this->send($this->action(), $message, ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'ping']);
        $this->assertSame(400, $mismatch->getStatusCode());
    }

    public function testGetIs405Json(): void
    {
        $response = $this->send($this->action(), null, [], 'GET');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST, OPTIONS', $response->getHeaderLine('Allow'));
        $this->assertIsArray(self::body($response));
    }

    public function testDeleteIs405(): void
    {
        $this->assertSame(405, $this->send($this->action(), null, [], 'DELETE')->getStatusCode());
    }

    public function testPreflightFromAllowedOrigin(): void
    {
        $response = $this->send($this->action(), null, ['Origin' => 'https://claude.ai', 'Authorization' => null], 'OPTIONS');
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://claude.ai', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('Mcp-Method', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testPreflightFromForeignOriginGetsNoCors(): void
    {
        $response = $this->send($this->action(), null, ['Origin' => 'https://evil.example', 'Authorization' => null], 'OPTIONS');
        $this->assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testUnexpectedExceptionIsInternalError(): void
    {
        $action = new McpAction(
            Config::fromArray(['require_tls' => false]),
            new McpServer('frontaccounting-mcp', '1.0.0', 'instructions', false),
            new SchemaToolFactory(50),
            function (): GraphQlGateway {
                throw new \LogicException('secret detail');
            }
        );
        $response = $this->send($action, self::toolsList());
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Internal error']],
            self::body($response)
        );
    }

    public function testStrayOutputDoesNotCorruptResponse(): void
    {
        $gql = new class extends FakeGraphQlGateway {
            public function execute(string $document, array $variables = [], ?string $operationName = null): array
            {
                if (strpos($document, 'customerList') !== false) {
                    echo "Notice: x";
                    trigger_error('x', E_USER_NOTICE);
                    return ['data' => ['customerList' => [['id' => '1', 'name' => 'Acme']]]];
                }
                return ['data' => ['me' => ['scope' => 'write']]];
            }
        };
        // Nothing printed reaches the client: the response body is separate, so
        // what would leak is output ahead of it, which PHPUnit sees here.
        $this->expectOutputString('');
        // What PHP does with no handler of its own claiming the notice: print it
        // (display_errors), as FrontAccounting would without graphql's handlers.
        set_error_handler(function (): bool {
            return false;
        });
        $displayErrors = ini_set('display_errors', '1');
        $level = ob_get_level();
        try {
            $response = $this->send($this->action([], $gql), [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => ['name' => 'customer_list', 'arguments' => (object) []],
            ]);
        } finally {
            restore_error_handler();
            ini_set('display_errors', (string) $displayErrors);
        }
        $this->assertSame($level, ob_get_level(), 'the action closes the buffer it opened');

        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'one JSON object: ' . $raw);
        $this->assertSame('{', $raw[0]);
        $this->assertFalse($decoded['result']['isError'] ?? false, $raw);
        $this->assertStringNotContainsString('Notice', $decoded['result']['content'][0]['text']);
        $this->assertStringNotContainsString('Notice', $raw);
    }
}
