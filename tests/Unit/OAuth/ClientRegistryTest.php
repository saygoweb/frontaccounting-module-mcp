<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\Config;
use FA\Mcp\OAuth\CimdFetcher;
use FA\Mcp\OAuth\ClientRegistry;
use FA\Mcp\OAuth\InMemoryStore;
use FA\Mcp\OAuth\OAuthError;
use FA\Mcp\Tests\Support\FakeHttpFetcher;
use FA\Mcp\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

class ClientRegistryTest extends TestCase
{
    private const CIMD_URL = 'https://app.example/oauth/client.json';
    private const IP = '203.0.113.1';

    private FakeHttpFetcher $http;
    private FixedClock $clock;
    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->http = new FakeHttpFetcher();
        $this->clock = new FixedClock();
        $this->store = new InMemoryStore();
    }

    /** @param bool|null $prune whether each chance to prune takes it; null: one in fifty at random */
    private function registry(array $config = [], ?bool $prune = null): ClientRegistry
    {
        $cimd = new CimdFetcher($this->http, function () {
            return ['93.184.216.34'];
        });
        $sample = $prune === null ? null : function () use ($prune): bool {
            return $prune;
        };
        return new ClientRegistry($this->store, $cimd, Config::fromArray($config), $this->clock, $sample);
    }

    /** A DCR client row last used 31 days ago, holding no refresh token. */
    private function idleClient(string $id): void
    {
        $this->store->saveClient([
            'client_id' => $id, 'kind' => 'dcr', 'client_name' => 'Idle',
            'redirect_uris' => '["https://idle.example/cb"]', 'created_at' => $this->clock->now() - 31 * 86400,
            'last_used_at' => null, 'fetched_at' => null, 'fetch_ok_at' => null, 'source_ip' => self::IP,
        ]);
    }

    private function expectOAuthError(callable $fn, string $error, int $status = 400): OAuthError
    {
        try {
            $fn();
        } catch (OAuthError $e) {
            $this->assertSame($error, $e->error());
            $this->assertSame($status, $e->httpStatus());
            return $e;
        }
        $this->fail('Expected OAuthError ' . $error);
    }

    private function metadata(array $overrides = []): array
    {
        return array_merge([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'application_type' => 'web',
        ], $overrides);
    }

    public function testRegisterAndResolve(): void
    {
        $registry = $this->registry();
        $client = $registry->register($this->metadata(), '203.0.113.1');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $client->clientId());
        $this->assertSame('dcr', $client->kind());
        $this->assertSame('Claude', $client->name());
        $this->assertSame(['https://claude.ai/api/mcp/auth_callback'], $client->redirectUris());

        $again = $registry->resolve($client->clientId(), self::IP);
        $this->assertSame($client->clientId(), $again->clientId());
        $this->assertSame('Claude', $again->name());
        $this->assertSame($client->redirectUris(), $again->redirectUris());
    }

    public function testDefaultName(): void
    {
        $meta = $this->metadata(['token_endpoint_auth_method' => 'none']);
        unset($meta['client_name']);
        $this->assertSame('MCP client', $this->registry()->register($meta, '203.0.113.1')->name());
    }

    public function testRateLimitPerIp(): void
    {
        $registry = $this->registry();
        for ($i = 0; $i < 10; $i++) {
            $registry->register($this->metadata(), '203.0.113.1');
        }
        $this->expectOAuthError(function () use ($registry) {
            $registry->register($this->metadata(), '203.0.113.1');
        }, 'invalid_request', 429);

        // Another address is unaffected; the same one recovers after an hour.
        $registry->register($this->metadata(), '203.0.113.2');
        $this->clock->advance(3601);
        $registry->register($this->metadata(), '203.0.113.1');
        $this->addToAssertionCount(1);
    }

    public function testBadRedirectRejected(): void
    {
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry) {
            $registry->register($this->metadata(['redirect_uris' => ['http://evil.example/cb']]), '203.0.113.1');
        }, 'invalid_redirect_uri');
        $this->expectOAuthError(function () use ($registry) {
            $registry->register($this->metadata(['redirect_uris' => []]), '203.0.113.1');
        }, 'invalid_redirect_uri');
        $this->expectOAuthError(function () use ($registry) {
            $registry->register($this->metadata(['redirect_uris' => 'https://claude.ai/cb']), '203.0.113.1');
        }, 'invalid_redirect_uri');
    }

    public function testConfidentialClientRejected(): void
    {
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry) {
            $registry->register(
                $this->metadata(['token_endpoint_auth_method' => 'client_secret_basic']),
                '203.0.113.1'
            );
        }, 'invalid_client_metadata');
    }

    public function testOverlongNameRejected(): void
    {
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry) {
            $registry->register($this->metadata(['client_name' => str_repeat('x', 256)]), '203.0.113.1');
        }, 'invalid_client_metadata');
    }

    public function testDcrDisabled(): void
    {
        $registry = $this->registry(['allow_dcr' => false]);
        $this->expectOAuthError(function () use ($registry) {
            $registry->register($this->metadata(), '203.0.113.1');
        }, 'invalid_request');
    }

    public function testUnknownClientIsInvalidClient(): void
    {
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolve('0123456789abcdef0123456789abcdef', self::IP);
        }, 'invalid_client', 401);
    }

    public function testCimdResolvesAndIsCached(): void
    {
        $this->http->respond(self::CIMD_URL, 200, json_encode([
            'client_id' => self::CIMD_URL,
            'client_name' => 'Example App',
            'redirect_uris' => ['https://app.example/callback'],
        ]));
        $registry = $this->registry();

        $client = $registry->resolve(self::CIMD_URL, self::IP);
        $this->assertSame('cimd', $client->kind());
        $this->assertSame('Example App', $client->name());
        $this->assertSame(['https://app.example/callback'], $client->redirectUris());

        $this->clock->advance(86399);
        $this->assertSame('Example App', $registry->resolve(self::CIMD_URL, self::IP)->name());
        $this->assertCount(1, $this->http->calls);

        $this->clock->advance(2);
        $registry->resolve(self::CIMD_URL, self::IP);
        $this->assertCount(2, $this->http->calls);
    }

    public function testCimdDisabled(): void
    {
        $registry = $this->registry(['allow_cimd' => false]);
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolve(self::CIMD_URL, self::IP);
        }, 'invalid_client', 401);
        $this->assertSame([], $this->http->calls);
    }

    public function testCimdUrlNeedsPath(): void
    {
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolve('https://app.example', self::IP);
        }, 'invalid_client', 401);
        $this->assertSame([], $this->http->calls);
    }

    private function cannedCimd(): void
    {
        $this->http->respond(self::CIMD_URL, 200, json_encode([
            'client_id' => self::CIMD_URL,
            'client_name' => 'Example App',
            'redirect_uris' => ['https://app.example/callback'],
        ]));
    }

    public function testResolveForTokenFindsDcrClient(): void
    {
        $registry = $this->registry();
        $client = $registry->register($this->metadata(), '203.0.113.1');
        $this->assertSame('Claude', $registry->resolveForToken($client->clientId())->name());
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolveForToken('0123456789abcdef0123456789abcdef');
        }, 'invalid_client', 401);
    }

    public function testResolveForTokenNeverFetches(): void
    {
        $this->cannedCimd();
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolveForToken(self::CIMD_URL);
        }, 'invalid_client', 401);
        $this->assertSame([], $this->http->calls);
    }

    public function testResolveForTokenIgnoresCacheTtl(): void
    {
        $this->cannedCimd();
        $registry = $this->registry();
        $registry->resolve(self::CIMD_URL, self::IP);

        $this->clock->advance(86400 * 10);
        $this->assertSame('Example App', $registry->resolveForToken(self::CIMD_URL)->name());
        $this->assertCount(1, $this->http->calls);
    }

    public function testResolveForTokenRefusesCimdWhenDisabled(): void
    {
        $this->cannedCimd();
        $this->registry()->resolve(self::CIMD_URL, self::IP);
        $registry = $this->registry(['allow_cimd' => false]);
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolveForToken(self::CIMD_URL);
        }, 'invalid_client', 401);
    }

    public function testFailedCimdFetchIsNegativelyCached(): void
    {
        $registry = $this->registry();
        for ($i = 0; $i < 3; $i++) {
            $this->expectOAuthError(function () use ($registry) {
                $registry->resolve(self::CIMD_URL, self::IP);
            }, 'invalid_client', 401);
        }
        $this->assertCount(1, $this->http->calls);

        // The failure is no client at the token endpoint either.
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolveForToken(self::CIMD_URL);
        }, 'invalid_client', 401);

        // After 300 s it is tried again, and can succeed.
        $this->clock->advance(299);
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolve(self::CIMD_URL, self::IP);
        }, 'invalid_client', 401);
        $this->assertCount(1, $this->http->calls);
        $this->clock->advance(1);
        $this->cannedCimd();
        $this->assertSame('Example App', $registry->resolve(self::CIMD_URL, self::IP)->name());
        $this->assertCount(2, $this->http->calls);
    }

    public function testFailedRefetchKeepsKnownClientForAWhile(): void
    {
        $this->cannedCimd();
        $registry = $this->registry();
        $registry->resolve(self::CIMD_URL, self::IP);

        // The cache is stale and the document's host is down.
        $this->clock->advance(86401);
        $this->http->responses = [];
        $this->assertSame('Example App', $registry->resolve(self::CIMD_URL, self::IP)->name());
        $this->assertCount(2, $this->http->calls);
        $this->assertSame('Example App', $registry->resolve(self::CIMD_URL, self::IP)->name());
        $this->assertSame('Example App', $registry->resolveForToken(self::CIMD_URL)->name());
        $this->assertCount(2, $this->http->calls);

        $this->clock->advance(300);
        $registry->resolve(self::CIMD_URL, self::IP);
        $this->assertCount(3, $this->http->calls);
    }

    public function testOverlongCimdUrlRefusedWithoutFetch(): void
    {
        $url = 'https://app.example/' . str_repeat('a', 236);
        $this->assertSame(256, strlen($url));
        $this->http->respond($url, 200, json_encode([
            'client_id' => $url, 'redirect_uris' => ['https://app.example/callback'],
        ]));
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry, $url) {
            $registry->resolve($url, self::IP);
        }, 'invalid_client', 401);
        $this->expectOAuthError(function () use ($registry, $url) {
            $registry->resolveForToken($url);
        }, 'invalid_client', 401);
        $this->assertSame([], $this->http->calls);
    }

    public function testPruneDropsExpiredRefreshRows(): void
    {
        $base = [
            'family' => str_repeat('f', 32), 'client_id' => 'c', 'company' => 1, 'login' => 'alice',
            'scope' => 'read', 'graphql_jti' => str_repeat('a', 32), 'rotated_at' => null,
            'revoked_at' => null, 'created_at' => 0,
        ];
        $now = $this->clock->now();
        $this->store->saveRefresh(array_merge($base, ['token_hash' => 'live', 'expires_at' => $now + 1]));
        $this->store->saveRefresh(array_merge($base, ['token_hash' => 'expired', 'expires_at' => $now - 1]));
        $this->store->saveRefresh(array_merge(
            $base,
            ['token_hash' => 'revoked', 'expires_at' => $now - 1, 'revoked_at' => $now - 10]
        ));

        $this->registry()->prune();
        $this->assertNotNull($this->store->findRefreshByHash('live'));
        $this->assertNull($this->store->findRefreshByHash('expired'));
        $this->assertNull($this->store->findRefreshByHash('revoked'));
    }

    public function testPruneKeepsAnIdleClientWithALiveRefreshToken(): void
    {
        $registry = $this->registry();
        $kept = $registry->register($this->metadata(), self::IP);
        $gone = $registry->register($this->metadata(), self::IP);
        $expired = $registry->register($this->metadata(), self::IP);
        $base = [
            'family' => str_repeat('f', 32), 'company' => 1, 'login' => 'alice', 'scope' => 'read',
            'graphql_jti' => str_repeat('a', 32),
            'rotated_at' => null, 'revoked_at' => null, 'created_at' => $this->clock->now(),
        ];
        // A refresh token longer-lived than the 30-day idle age.
        $this->store->saveRefresh(array_merge($base, [
            'token_hash' => 'k', 'client_id' => $kept->clientId(), 'expires_at' => $this->clock->now() + 90 * 86400
        ]));
        $this->store->saveRefresh(array_merge($base, [
            'token_hash' => 'e', 'client_id' => $expired->clientId(), 'expires_at' => $this->clock->now() + 86400
        ]));

        $this->clock->advance(31 * 86400);
        $registry->prune();

        $this->assertNotNull($this->store->findClient($kept->clientId()));
        $this->assertNull($this->store->findClient($gone->clientId()));
        $this->assertNull($this->store->findClient($expired->clientId()));
    }

    public function testCimdResolutionPrunesWithDcrDisabled(): void
    {
        $this->idleClient('idle');
        $this->cannedCimd();
        $this->registry(['allow_dcr' => false], true)->resolve(self::CIMD_URL, self::IP);
        $this->assertNull($this->store->findClient('idle'));
        $this->assertNotNull($this->store->findClient(self::CIMD_URL));
    }

    public function testTokenEndpointResolutionPrunesWithDcrDisabled(): void
    {
        $this->idleClient('idle');
        $registry = $this->registry(['allow_dcr' => false], true);
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolveForToken('0123456789abcdef0123456789abcdef');
        }, 'invalid_client', 401);
        $this->assertNull($this->store->findClient('idle'));
    }

    public function testNoPruneWhenTheSampleSaysNo(): void
    {
        $this->idleClient('idle');
        $this->cannedCimd();
        $registry = $this->registry(['allow_dcr' => false], false);
        $registry->resolve(self::CIMD_URL, self::IP);
        $registry->resolveForToken(self::CIMD_URL);
        $this->assertNotNull($this->store->findClient('idle'));
    }

    public function testStaleCimdGraceEndsTwoCachePeriodsAfterTheLastGoodFetch(): void
    {
        $this->cannedCimd();
        $registry = $this->registry(['cimd_cache_ttl' => 3600]);
        $registry->resolve(self::CIMD_URL, self::IP);
        $this->assertNotNull($this->store->findClient(self::CIMD_URL)['fetch_ok_at']);

        // Host down: failed refetches keep the document while the last good
        // fetch is younger than 2 x 3600 s.
        $this->http->responses = [];
        $this->clock->advance(3601);
        $this->assertSame('Example App', $registry->resolve(self::CIMD_URL, self::IP)->name());
        $this->clock->advance(3300); // 6901 s since the good fetch
        $this->assertSame('Example App', $registry->resolve(self::CIMD_URL, self::IP)->name());
        $calls = count($this->http->calls);

        // At 7200 s - still inside the 300 s backoff of that last failure,
        // so no fetch - the cached document is no longer used.
        $this->clock->advance(299); // 7200 s
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolve(self::CIMD_URL, self::IP);
        }, 'invalid_client', 401);
        $this->assertCount($calls, $this->http->calls);

        // After the backoff it is fetched again, fails, and is still refused.
        $this->clock->advance(300);
        $this->expectOAuthError(function () use ($registry) {
            $registry->resolve(self::CIMD_URL, self::IP);
        }, 'invalid_client', 401);
        $this->assertCount($calls + 1, $this->http->calls);

        // Once the host is back a fetch succeeds and starts a new grace period.
        $this->clock->advance(300);
        $this->cannedCimd();
        $this->assertSame('Example App', $registry->resolve(self::CIMD_URL, self::IP)->name());
        $this->assertSame($this->clock->now(), $this->store->findClient(self::CIMD_URL)['fetch_ok_at']);
    }

    public function testTooManyRedirectUrisRejected(): void
    {
        $uris = [];
        for ($i = 0; $i < 11; $i++) {
            $uris[] = 'https://app.example/cb' . $i;
        }
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry, $uris) {
            $registry->register($this->metadata(['redirect_uris' => $uris]), self::IP);
        }, 'invalid_client_metadata');
        $this->assertCount(10, $registry->register(
            $this->metadata(['redirect_uris' => array_slice($uris, 0, 10)]),
            self::IP
        )->redirectUris());
    }

    public function testOverlongRedirectUriRejected(): void
    {
        $prefix = 'https://app.example/';
        $registry = $this->registry();
        $this->expectOAuthError(function () use ($registry, $prefix) {
            $uri = $prefix . str_repeat('a', 513 - strlen($prefix));
            $registry->register($this->metadata(['redirect_uris' => [$uri]]), self::IP);
        }, 'invalid_client_metadata');
        $uri = $prefix . str_repeat('a', 512 - strlen($prefix));
        $this->assertSame([$uri], $registry->register(
            $this->metadata(['redirect_uris' => [$uri]]),
            self::IP
        )->redirectUris());
    }

    public function testClientNameLimitIsInBytes(): void
    {
        $registry = $this->registry();
        // 86 characters, 258 bytes.
        $this->expectOAuthError(function () use ($registry) {
            $registry->register($this->metadata(['client_name' => str_repeat('✓', 86)]), self::IP);
        }, 'invalid_client_metadata');
        $this->assertSame(
            str_repeat('✓', 85),
            $registry->register($this->metadata(['client_name' => str_repeat('✓', 85)]), self::IP)->name()
        );
    }

    public function testNewCimdLookupsAreRateLimitedPerIp(): void
    {
        $urls = [];
        for ($i = 1; $i <= 3; $i++) {
            $urls[$i] = 'https://app' . $i . '.example/client.json';
            $this->http->respond($urls[$i], 200, json_encode([
                'client_id' => $urls[$i], 'client_name' => 'App ' . $i,
                'redirect_uris' => ['https://app' . $i . '.example/cb'],
            ]));
        }
        $registry = $this->registry(['dcr_rate_limit' => 2]);
        $registry->resolve($urls[1], self::IP);
        $registry->resolve($urls[2], self::IP);

        $e = $this->expectOAuthError(function () use ($registry, $urls) {
            $registry->resolve($urls[3], self::IP);
        }, 'invalid_client', 401);
        $this->assertSame('Too many client lookups', $e->description());
        $this->assertCount(2, $this->http->calls);
        $this->assertNull($this->store->findClient($urls[3]));

        // A client already known still resolves, even refetched; another
        // address has its own allowance; an hour later this one has again.
        $this->assertSame('App 1', $registry->resolve($urls[1], self::IP)->name());
        $this->clock->advance(86401);
        $this->assertSame('App 2', $registry->resolve($urls[2], self::IP)->name());
        $this->assertSame('App 3', $registry->resolve($urls[3], '198.51.100.1')->name());
    }

    public function testCimdLookupsDoNotUseUpTheRegistrationAllowance(): void
    {
        $this->http->respond(self::CIMD_URL, 200, json_encode([
            'client_id' => self::CIMD_URL, 'client_name' => 'Example App',
            'redirect_uris' => ['https://app.example/callback'],
        ]));
        $registry = $this->registry(['dcr_rate_limit' => 1]);
        $registry->resolve(self::CIMD_URL, self::IP);

        $this->assertSame('dcr', $registry->register($this->metadata(), self::IP)->kind());
    }
}
