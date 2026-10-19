<?php

namespace FA\Mcp\Tests\Unit\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\OAuth\CimdFetcher;
use FA\Mcp\OAuth\ClientRegistry;
use FA\Mcp\OAuth\Http\RegisterAction;
use FA\Mcp\OAuth\InMemoryStore;
use FA\Mcp\Tests\Support\FakeHttpFetcher;
use FA\Mcp\Tests\Support\FixedClock;
use FA\Mcp\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RegisterActionTest extends TestCase
{
    private const BASE = 'https://fa.example.com/modules/mcp';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const PATH = '/modules/mcp/oauth/register';

    private InMemoryStore $store;

    /** @param array<string, mixed> $config */
    private function call(ServerRequestInterface $request, array $config = []): ResponseInterface
    {
        $config = Config::fromArray($config + ['base_url' => self::BASE]);
        $clock = new FixedClock();
        $this->store = new InMemoryStore();
        $clients = new ClientRegistry(
            $this->store,
            new CimdFetcher(new FakeHttpFetcher(), function () {
                return ['93.184.216.34'];
            }),
            $config,
            $clock
        );
        return (new RegisterAction($config, $clients, $clock))($request, Requests::response());
    }

    public function testRegistersAPublicClient(): void
    {
        $res = $this->call(Requests::json(self::PATH, (string) json_encode([
            'client_name' => 'Claude 🧪', 'redirect_uris' => [self::REDIRECT],
        ])));

        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $body = Requests::body($res);
        $this->assertSame(
            ['client_id', 'client_name', 'redirect_uris', 'token_endpoint_auth_method',
                'grant_types', 'response_types', 'client_id_issued_at'],
            array_keys($body)
        );
        $this->assertSame(32, strlen($body['client_id']));
        $this->assertSame('Claude 🧪', $body['client_name']);
        $this->assertSame([self::REDIRECT], $body['redirect_uris']);
        $this->assertSame('none', $body['token_endpoint_auth_method']);
        $this->assertSame(['authorization_code', 'refresh_token'], $body['grant_types']);
        $this->assertSame(['code'], $body['response_types']);
        $this->assertSame(1700000000, $body['client_id_issued_at']);
        $this->assertNotNull($this->store->findClient($body['client_id']));
    }

    public function testTheRateLimitPassesThroughAs429(): void
    {
        $request = Requests::json(self::PATH, (string) json_encode(['redirect_uris' => [self::REDIRECT]]));
        $res = $this->call($request, ['dcr_rate_limit' => 1]);
        $this->assertSame(201, $res->getStatusCode());

        $config = Config::fromArray(['base_url' => self::BASE, 'dcr_rate_limit' => 1]);
        $clients = new ClientRegistry($this->store, new CimdFetcher(new FakeHttpFetcher()), $config, new FixedClock());
        $res = (new RegisterAction($config, $clients, new FixedClock()))($request, Requests::response());
        $this->assertSame(429, $res->getStatusCode());
        $this->assertSame('invalid_request', Requests::body($res)['error']);
    }

    public function testABadRedirectUriIsAnOAuthError(): void
    {
        $res = $this->call(Requests::json(self::PATH, (string) json_encode([
            'redirect_uris' => ['http://evil.example/cb'],
        ])));
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('invalid_redirect_uri', Requests::body($res)['error']);
    }

    public function testABodyThatIsNotAJsonObjectIsRefused(): void
    {
        $res = $this->call(Requests::json(self::PATH, '[1,2]'));
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('invalid_client_metadata', Requests::body($res)['error']);
    }

    public function testPlainHttpIsRefusedWhenTlsIsRequired(): void
    {
        $res = $this->call(Requests::json(self::PATH, '{}', ['HTTPS' => '']));
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('HTTPS is required', Requests::body($res)['error_description']);
    }

    public function testAnAllowedOriginGetsCors(): void
    {
        $request = Requests::json(self::PATH, (string) json_encode(['redirect_uris' => [self::REDIRECT]]), [], [
            'Origin' => 'https://claude.ai',
        ]);
        $res = $this->call($request);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('https://claude.ai', $res->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflight(): void
    {
        $res = $this->call(Requests::options(self::PATH));
        $this->assertSame(204, $res->getStatusCode());
        $this->assertSame('https://claude.ai', $res->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('POST', $res->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Content-Type', $res->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function testAPreflightFromAForeignOriginGetsNoCors(): void
    {
        $res = $this->call(Requests::options(self::PATH, 'https://evil.example'));
        $this->assertSame(204, $res->getStatusCode());
        $this->assertSame('', $res->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('POST, OPTIONS', $res->getHeaderLine('Allow'));
    }
}
