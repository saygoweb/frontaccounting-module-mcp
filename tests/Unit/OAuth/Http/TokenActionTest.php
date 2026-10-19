<?php

namespace FA\Mcp\Tests\Unit\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\OAuth\AuthorizationServer;
use FA\Mcp\OAuth\CimdFetcher;
use FA\Mcp\OAuth\Client;
use FA\Mcp\OAuth\ClientRegistry;
use FA\Mcp\OAuth\Http\TokenAction;
use FA\Mcp\OAuth\InMemoryStore;
use FA\Mcp\OAuth\Metadata;
use FA\Mcp\Tests\Support\FakeAccountGateway;
use FA\Mcp\Tests\Support\FakeHttpFetcher;
use FA\Mcp\Tests\Support\FixedClock;
use FA\Mcp\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class TokenActionTest extends TestCase
{
    private const BASE = 'https://fa.example.com/modules/mcp';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private FakeAccountGateway $accounts;
    private AuthorizationServer $server;
    private Client $client;
    private Config $config;

    protected function setUp(): void
    {
        $clock = new FixedClock();
        $store = new InMemoryStore();
        $this->config = Config::fromArray(['base_url' => self::BASE]);
        $this->accounts = new FakeAccountGateway();
        $this->accounts->clock = $clock;
        $this->accounts->addUser(1, 'alice');
        $clients = new ClientRegistry(
            $store,
            new CimdFetcher(new FakeHttpFetcher(), function () {
                return ['93.184.216.34'];
            }),
            $this->config,
            $clock
        );
        $this->client = $clients->register(
            ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]],
            '203.0.113.1'
        );
        $this->server = new AuthorizationServer(
            $store,
            $clients,
            $this->accounts,
            new Metadata(self::BASE, true, true),
            $clock,
            $this->config
        );
    }

    private function action(?Config $config = null, ?RequestContext $context = null): TokenAction
    {
        return new TokenAction($config ?? $this->config, $this->server, $context);
    }

    private function code(): string
    {
        $validated = $this->server->validateAuthorizeRequest([
            'response_type' => 'code', 'client_id' => $this->client->clientId(), 'redirect_uri' => self::REDIRECT,
            'code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256',
        ], '203.0.113.1');
        return $this->server->issueCode($validated, 1, 'alice', 'read');
    }

    /** @param array<string, string> $server */
    private function post(array $overrides = [], array $server = [], ?Config $config = null): ResponseInterface
    {
        $post = array_merge([
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->clientId(),
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
            'resource' => self::BASE . '/',
        ], $overrides);
        $request = Requests::form('/modules/mcp/oauth/token', $post, [], $server);
        return $this->action($config)($request, Requests::response());
    }

    public function testSuccessIsNotCached(): void
    {
        $res = $this->post(['code' => $this->code()]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('no-cache', $res->getHeaderLine('Pragma'));
        $body = Requests::body($res);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame($this->accounts->issued[0]['token'], $body['access_token']);
        $this->assertSame('read', $body['scope']);
        $this->assertSame(3600, $body['expires_in']);
        $this->assertArrayHasKey('refresh_token', $body);
    }

    public function testRefreshGrant(): void
    {
        $first = Requests::body($this->post(['code' => $this->code()]));
        $res = $this->post(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertNotSame($first['refresh_token'], Requests::body($res)['refresh_token']);
    }

    public function testInvalidGrantIs400Json(): void
    {
        $res = $this->post(['code' => 'no-such-code']);
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('invalid_grant', Requests::body($res)['error']);
        $this->assertSame('', $res->getHeaderLine('WWW-Authenticate'));
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
    }

    public function testUnsupportedGrantType(): void
    {
        $res = $this->post(['grant_type' => 'password']);
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('unsupported_grant_type', Requests::body($res)['error']);
    }

    public function testNoFormBodyIsInvalidRequest(): void
    {
        $request = Requests::json('/modules/mcp/oauth/token', '{"grant_type":"authorization_code"}');
        $res = $this->action()($request, Requests::response());
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('invalid_request', Requests::body($res)['error']);
    }

    public function testInvalidClientIs401WithChallenge(): void
    {
        $res = $this->post(['code' => 'x', 'client_id' => 'unknown-client']);
        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('invalid_client', Requests::body($res)['error']);
        $this->assertSame('Bearer', $res->getHeaderLine('WWW-Authenticate'));
    }

    public function testServerErrorFromTheMintIs500WithoutTheCause(): void
    {
        $this->accounts->beforeIssue = function () {
            throw new \RuntimeException('db down secret');
        };
        $res = $this->post(['code' => $this->code()]);
        $this->assertSame(500, $res->getStatusCode());
        $this->assertSame('server_error', Requests::body($res)['error']);
        $this->assertStringNotContainsString('secret', (string) $res->getBody());
    }

    public function testUnexpectedExceptionIsAGeneric500(): void
    {
        $this->accounts->beforeIssue = function () {
            throw new \Error('boom secret');
        };
        $res = $this->post(['code' => $this->code()]);
        $this->assertSame(500, $res->getStatusCode());
        $this->assertSame(['error' => 'server_error'], Requests::body($res));
    }

    public function testUnexpectedExceptionNamesTheCauseWithDebug(): void
    {
        $this->accounts->beforeIssue = function () {
            throw new \Error('boom');
        };
        $config = Config::fromArray(['base_url' => self::BASE, 'debug' => true]);
        $body = Requests::body($this->post(['code' => $this->code()], [], $config));
        $this->assertSame('server_error', $body['error']);
        $this->assertStringContainsString('boom', $body['error_description']);
    }

    public function testPlainHttpIsRefusedWhenTlsIsRequired(): void
    {
        $res = $this->post(['code' => 'x'], ['HTTPS' => '']);
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(
            ['error' => 'invalid_request', 'error_description' => 'HTTPS is required'],
            Requests::body($res)
        );
    }

    public function testTheInjectedRequestContextDecidesHttps(): void
    {
        // The container's context (trust_proxy on, X-Forwarded-Proto https)
        // is the one used; the endpoint does not rebuild it from the request.
        $request = Requests::form('/modules/mcp/oauth/token', ['grant_type' => 'x'], [], ['HTTPS' => ''])
            ->withHeader('X-Forwarded-Proto', 'https');
        $res = $this->action(null, RequestContext::fromRequest($request, true))($request, Requests::response());
        $this->assertSame('unsupported_grant_type', Requests::body($res)['error']);
        $res = $this->action(null, RequestContext::fromRequest($request, false))($request, Requests::response());
        $this->assertSame('HTTPS is required', Requests::body($res)['error_description']);
    }

    public function testAnAllowedOriginGetsCors(): void
    {
        $request = Requests::form('/modules/mcp/oauth/token', ['grant_type' => 'x'])
            ->withHeader('Origin', 'https://claude.ai');
        $res = $this->action()($request, Requests::response());
        $this->assertSame('https://claude.ai', $res->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('Origin', $res->getHeaderLine('Vary'));
    }

    public function testAForeignOriginGetsNoCors(): void
    {
        $request = Requests::form('/modules/mcp/oauth/token', ['grant_type' => 'x'])
            ->withHeader('Origin', 'https://evil.example');
        $res = $this->action()($request, Requests::response());
        $this->assertSame('', $res->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflight(): void
    {
        $res = $this->action()(Requests::options('/modules/mcp/oauth/token'), Requests::response());
        $this->assertSame(204, $res->getStatusCode());
        $this->assertSame('https://claude.ai', $res->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('POST, OPTIONS', $res->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Content-Type', $res->getHeaderLine('Access-Control-Allow-Headers'));
    }
}
