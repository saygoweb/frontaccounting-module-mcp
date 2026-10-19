<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\Config;
use FA\Mcp\OAuth\AuthorizationServer;
use FA\Mcp\OAuth\CimdFetcher;
use FA\Mcp\OAuth\Client;
use FA\Mcp\OAuth\ClientRegistry;
use FA\Mcp\OAuth\InMemoryStore;
use FA\Mcp\OAuth\Metadata;
use FA\Mcp\OAuth\OAuthError;
use FA\Mcp\Tests\Support\FakeAccountGateway;
use FA\Mcp\Tests\Support\FakeHttpFetcher;
use FA\Mcp\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

class AuthorizationServerTest extends TestCase
{
    private const BASE = 'https://fa.example.com/modules/mcp';
    private const MCP = self::BASE . '/';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const COMPANY = 1;
    private const LOGIN = 'alice';
    private const IP = '203.0.113.1';

    private FixedClock $clock;
    private InMemoryStore $store;
    private FakeAccountGateway $accounts;
    private ClientRegistry $clients;
    private FakeHttpFetcher $http;
    private Client $client;
    private Config $config;
    private AuthorizationServer $server;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $this->store = new InMemoryStore();
        $this->accounts = new FakeAccountGateway();
        $this->accounts->addUser(self::COMPANY, self::LOGIN);
        $this->config = Config::fromArray(['base_url' => self::BASE]);
        $this->http = new FakeHttpFetcher();
        $cimd = new CimdFetcher($this->http, function () {
            return ['93.184.216.34'];
        });
        $this->clients = new ClientRegistry($this->store, $cimd, $this->config, $this->clock);
        $this->client = $this->clients->register(
            ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]],
            self::IP
        );
        $this->server = $this->serverFor(self::BASE);
    }

    private function serverFor(string $baseUrl): AuthorizationServer
    {
        return new AuthorizationServer(
            $this->store,
            $this->clients,
            $this->accounts,
            new Metadata($baseUrl, true, true),
            $this->clock,
            $this->config
        );
    }

    private function authorizeQuery(array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => $this->client->clientId(),
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope' => 'read write',
            'state' => 'xyz',
            'resource' => self::MCP,
        ], $overrides);
    }

    private function code(string $scope = 'write', int $company = self::COMPANY, string $login = self::LOGIN): string
    {
        $validated = $this->server->validateAuthorizeRequest($this->authorizeQuery(), self::IP);
        return $this->server->issueCode($validated, $company, $login, $scope);
    }

    private function exchange(string $code, array $overrides = []): array
    {
        return $this->server->token(array_merge([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->client->clientId(),
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
            'resource' => self::MCP,
        ], $overrides));
    }

    private function refresh(string $refreshToken, ?string $clientId = null): array
    {
        return $this->server->token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId ?? $this->client->clientId(),
        ]);
    }

    private function refreshRow(string $refreshToken): ?array
    {
        return $this->store->findRefreshByHash(hash('sha256', $refreshToken));
    }

    private function expectOAuthError(callable $fn, string $error, int $status = 400): OAuthError
    {
        try {
            $fn();
        } catch (OAuthError $e) {
            $this->assertSame($error, $e->error(), $e->description());
            $this->assertSame($status, $e->httpStatus());
            return $e;
        }
        $this->fail('Expected OAuthError ' . $error);
    }

    public function testValidateAuthorizeRequest(): void
    {
        $v = $this->server->validateAuthorizeRequest($this->authorizeQuery(), self::IP);
        $this->assertSame($this->client->clientId(), $v['client']->clientId());
        $this->assertSame(self::REDIRECT, $v['redirectUri']);
        $this->assertSame('xyz', $v['state']);
        $this->assertSame(self::CHALLENGE, $v['codeChallenge']);
        $this->assertSame('write', $v['scope']);
        $this->assertSame(self::MCP, $v['resource']);
    }

    public function requestedScopes(): array
    {
        return [
            'write alone' => ['write', 'write'],
            'read and write' => ['read write', 'write'],
            'read' => ['read', 'read'],
            'empty' => ['', 'read'],
            'unknown' => ['admin', 'read'],
            'write inside another word' => ['rewrite', 'read'],
        ];
    }

    /** @dataProvider requestedScopes */
    public function testRequestedScopeIsNormalised(string $requested, string $expected): void
    {
        $v = $this->server->validateAuthorizeRequest($this->authorizeQuery(['scope' => $requested]), self::IP);
        $this->assertSame($expected, $v['scope']);
    }

    public function testMissingScopeIsRead(): void
    {
        $query = $this->authorizeQuery();
        unset($query['scope']);
        $this->assertSame('read', $this->server->validateAuthorizeRequest($query, self::IP)['scope']);
    }

    public function testIssueCodeRefusesAnUnknownScope(): void
    {
        $validated = $this->server->validateAuthorizeRequest($this->authorizeQuery(), self::IP);
        $this->expectException(\InvalidArgumentException::class);
        $this->server->issueCode($validated, self::COMPANY, self::LOGIN, 'admin');
    }

    public function testFullCodeFlowIssuesGraphQlTokenAndRefresh(): void
    {
        $code = $this->code('write');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $code);
        $response = $this->exchange($code);

        $this->assertCount(1, $this->accounts->issued);
        $issued = $this->accounts->issued[0];
        $this->assertSame(self::COMPANY, $issued['company']);
        $this->assertSame(self::LOGIN, $issued['login']);
        $this->assertSame('Claude (MCP) – Claude', $issued['label']);
        $this->assertSame('write', $issued['scope']);
        $this->assertSame(3600, $issued['ttl']);

        $this->assertSame($issued['token'], $response['access_token']);
        $this->assertSame('Bearer', $response['token_type']);
        $this->assertSame(3600, $response['expires_in']);
        $this->assertSame('write', $response['scope']);
        // 256 bits, base64url without padding.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $response['refresh_token']);

        $row = $this->refreshRow($response['refresh_token']);
        $this->assertSame(self::COMPANY, $row['company']);
        $this->assertSame(self::LOGIN, $row['login']);
        $this->assertSame($issued['jti'], $row['graphql_jti']);
        $this->assertSame($this->clock->now() + 2592000, $row['expires_at']);
    }

    public function testReadGrantIssuesAReadToken(): void
    {
        $response = $this->exchange($this->code('read'));
        $this->assertSame('read', $response['scope']);
        $this->assertSame('read', $this->accounts->issued[0]['scope']);
    }

    public function testConfiguredLifetimesAreUsed(): void
    {
        $this->config = Config::fromArray([
            'base_url' => self::BASE, 'access_token_ttl' => 600, 'refresh_token_ttl' => 7200,
        ]);
        $this->server = $this->serverFor(self::BASE);
        $response = $this->exchange($this->code());

        $this->assertSame(600, $this->accounts->issued[0]['ttl']);
        $this->assertSame(600, $response['expires_in']);
        $this->assertSame($this->clock->now() + 7200, $this->refreshRow($response['refresh_token'])['expires_at']);
    }

    public function testTokenLabelIsCutTo255Bytes(): void
    {
        $long = $this->clients->register(
            ['client_name' => str_repeat('é', 255), 'redirect_uris' => [self::REDIRECT]],
            self::IP
        );
        $validated = $this->server->validateAuthorizeRequest(
            $this->authorizeQuery(['client_id' => $long->clientId()]),
            self::IP
        );
        $this->exchange(
            $this->server->issueCode($validated, self::COMPANY, self::LOGIN, 'read'),
            ['client_id' => $long->clientId()]
        );

        $label = $this->accounts->issued[0]['label'];
        $this->assertLessThanOrEqual(255, strlen($label));
        $this->assertGreaterThanOrEqual(254, strlen($label));
        $this->assertStringStartsWith('Claude (MCP) – éé', $label);
        // Cut on a character boundary: still valid UTF-8.
        $this->assertSame(1, preg_match('//u', $label));
    }

    public function testCodeSingleUse(): void
    {
        $code = $this->code();
        $first = $this->exchange($code);
        $firstJti = $this->accounts->issued[0]['jti'];

        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code);
        }, 'invalid_grant');
        $this->assertContains($firstJti, $this->accounts->revokedIds());
        $this->assertSame(['company' => self::COMPANY, 'jti' => $firstJti], $this->accounts->revoked[0]);
        $this->assertCount(1, $this->accounts->issued);

        // The refresh token issued from the code is dead with it.
        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');
    }

    public function testCodeExpiresAfter60s(): void
    {
        $code = $this->code();
        $this->clock->advance(61);
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code);
        }, 'invalid_grant');
        $this->assertSame([], $this->accounts->issued);
    }

    public function testUnknownCode(): void
    {
        $this->expectOAuthError(function () {
            $this->exchange(str_repeat('a', 64));
        }, 'invalid_grant');
    }

    public function testPkceMismatch(): void
    {
        $code = $this->code();
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code, ['code_verifier' => str_repeat('b', 43)]);
        }, 'invalid_grant');
        $this->assertSame([], $this->accounts->issued);
    }

    public function testRedirectUriMismatch(): void
    {
        $code = $this->code();
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code, ['redirect_uri' => 'https://claude.ai/other']);
        }, 'invalid_grant');
    }

    public function testCodeBoundToClient(): void
    {
        $other = $this->clients->register(['redirect_uris' => [self::REDIRECT]], self::IP);
        $code = $this->code();
        $this->expectOAuthError(function () use ($code, $other) {
            $this->exchange($code, ['client_id' => $other->clientId()]);
        }, 'invalid_grant');
    }

    public function testResourceMismatchIsInvalidTarget(): void
    {
        $code = $this->code();
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code, ['resource' => 'https://other.example/modules/mcp/']);
        }, 'invalid_target');
    }

    public function testCodeIsBoundToItsAuthorizeResource(): void
    {
        // Authorized for one resource URL, exchanged naming another that the
        // token endpoint would itself accept (RFC 8707: they must agree).
        $a = $this->serverFor('https://a.example/modules/mcp');
        $validated = $a->validateAuthorizeRequest(
            $this->authorizeQuery(['resource' => 'https://a.example/modules/mcp/']),
            self::IP
        );
        $code = $a->issueCode($validated, self::COMPANY, self::LOGIN, 'write');
        $this->expectOAuthError(function () use ($code) {
            $this->serverFor('https://b.example/modules/mcp')->token([
                'grant_type' => 'authorization_code', 'code' => $code,
                'client_id' => $this->client->clientId(), 'redirect_uri' => self::REDIRECT,
                'code_verifier' => self::VERIFIER, 'resource' => 'https://b.example/modules/mcp/',
            ]);
        }, 'invalid_target');
    }

    public function testCodeResourceMayBeOmittedAtTheTokenEndpoint(): void
    {
        $code = $this->code();
        $post = [
            'grant_type' => 'authorization_code', 'code' => $code,
            'client_id' => $this->client->clientId(), 'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ];
        $this->assertArrayHasKey('access_token', $this->server->token($post));
    }

    public function testResourceTrailingSlashIgnored(): void
    {
        $response = $this->exchange($this->code(), ['resource' => self::BASE]);
        $this->assertArrayHasKey('access_token', $response);
    }

    public function testMissingParameterIsInvalidRequest(): void
    {
        $code = $this->code();
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code, ['code_verifier' => '']);
        }, 'invalid_request');

        // The failed request used the code up.
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code);
        }, 'invalid_grant');
        $this->assertSame([], $this->accounts->issued);
    }

    public function testTokenUnknownClientIsInvalidClient(): void
    {
        $code = $this->code();
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code, ['client_id' => 'nope']);
        }, 'invalid_client', 401);
    }

    public function testCodeConsumedEvenWhenClientUnknown(): void
    {
        $code = $this->code();
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code, ['client_id' => 'nope']);
        }, 'invalid_client', 401);

        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code);
        }, 'invalid_grant');
        $this->assertSame([], $this->accounts->issued);
    }

    public function testUsedCodeReplayedAfterExpiryRevokesFamily(): void
    {
        $code = $this->code();
        $first = $this->exchange($code);
        $firstJti = $this->accounts->issued[0]['jti'];

        $this->clock->advance(61);
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code);
        }, 'invalid_grant');
        $this->assertContains($firstJti, $this->accounts->revokedIds());
        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');
    }

    public function testTokenEndpointNeverFetchesCimd(): void
    {
        $url = 'https://app.example/oauth/client.json';
        $this->http->respond($url, 200, (string)json_encode([
            'client_id' => $url, 'client_name' => 'App', 'redirect_uris' => [self::REDIRECT],
        ]));
        $code = $this->code();
        $this->expectOAuthError(function () use ($code, $url) {
            $this->exchange($code, ['client_id' => $url]);
        }, 'invalid_client', 401);
        $this->expectOAuthError(function () use ($url) {
            $this->refresh(str_repeat('c', 43), $url);
        }, 'invalid_client', 401);
        $this->assertSame([], $this->http->calls);
    }

    public function testCimdClientFullFlowAfterCacheExpiry(): void
    {
        $url = 'https://app.example/oauth/client.json';
        $this->http->respond($url, 200, (string)json_encode([
            'client_id' => $url, 'client_name' => 'App', 'redirect_uris' => [self::REDIRECT],
        ]));
        $validated = $this->server->validateAuthorizeRequest($this->authorizeQuery(['client_id' => $url]), self::IP);
        $first = $this->exchange(
            $this->server->issueCode($validated, self::COMPANY, self::LOGIN, 'read'),
            ['client_id' => $url]
        );

        // Long after the CIMD cache has gone stale, refresh still works from the store alone.
        $this->clock->advance(86400 * 3);
        $this->refresh($first['refresh_token'], $url);
        $this->assertCount(1, $this->http->calls);
    }

    public function testRefreshRotates(): void
    {
        $first = $this->exchange($this->code());
        $firstJti = $this->accounts->issued[0]['jti'];

        $this->clock->advance(600);
        $second = $this->refresh($first['refresh_token']);
        $secondJti = $this->accounts->issued[1]['jti'];

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertSame('write', $second['scope']);
        $this->assertSame('write', $this->accounts->issued[1]['scope']);
        $this->assertSame(3600, $second['expires_in']);
        $this->assertContains($firstJti, $this->accounts->revokedIds());
        $this->assertNotContains($secondJti, $this->accounts->revokedIds());

        // Presenting the rotated token again kills the whole family.
        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');
        $this->assertContains($secondJti, $this->accounts->revokedIds());
        $this->expectOAuthError(function () use ($second) {
            $this->refresh($second['refresh_token']);
        }, 'invalid_grant');
        $this->assertCount(2, $this->accounts->issued);
    }

    public function testRefreshExpires(): void
    {
        $first = $this->exchange($this->code());
        $this->clock->advance(2592000);
        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');
    }

    public function testRefreshFailsWhenGraphQlTokenRevoked(): void
    {
        $first = $this->exchange($this->code());
        $this->accounts->revokeByUser($this->accounts->issued[0]['jti']);

        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');
        $this->assertCount(1, $this->accounts->issued);
        $this->assertNotNull($this->refreshRow($first['refresh_token'])['revoked_at']);
    }

    public function testRefreshWithExpiredAccessTokenSucceeds(): void
    {
        $first = $this->exchange($this->code());
        $oldJti = $this->accounts->issued[0]['jti'];
        $family = $this->refreshRow($first['refresh_token'])['family'];

        // The access token (3600 s) has expired; the refresh token (30 days) has not.
        $this->clock->advance(7200);
        $second = $this->refresh($first['refresh_token']);

        $this->assertCount(2, $this->accounts->issued);
        $this->assertSame($this->accounts->issued[1]['token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertSame(3600, $second['expires_in']);
        $this->assertSame([$oldJti], $this->accounts->revokedIds());
        $this->assertSame([$this->accounts->issued[1]['jti']], $this->accounts->liveJtis());

        $newRow = $this->refreshRow($second['refresh_token']);
        $this->assertSame($family, $newRow['family']);
        $this->assertSame($this->accounts->issued[1]['jti'], $newRow['graphql_jti']);
        $this->assertNull($newRow['rotated_at']);
        $this->assertSame($this->clock->now(), $this->refreshRow($first['refresh_token'])['rotated_at']);
    }

    public function testRefreshForDeactivatedUserIsInvalidGrant(): void
    {
        $first = $this->exchange($this->code());
        $liveBefore = $this->accounts->liveJtis();
        $this->accounts->setActive(self::COMPANY, self::LOGIN, false);

        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');

        // Nothing rotated or revoked, and no new token left live.
        $row = $this->refreshRow($first['refresh_token']);
        $this->assertNull($row['rotated_at']);
        $this->assertNull($row['revoked_at']);
        $this->assertCount(1, $this->accounts->issued);
        $this->assertSame([], $this->accounts->revoked);
        $this->assertSame($liveBefore, $this->accounts->liveJtis());

        // Reactivated, the same refresh token works again.
        $this->accounts->setActive(self::COMPANY, self::LOGIN, true);
        $second = $this->refresh($first['refresh_token']);
        $this->assertSame($this->accounts->issued[1]['token'], $second['access_token']);
    }

    public function testCodeCarriesCompany(): void
    {
        $this->accounts->addUser(2, 'bob');
        $code = $this->code('read', 2, 'bob');
        $codeRow = $this->store->findCode(hash('sha256', $code));
        $this->assertSame(2, $codeRow['company']);
        $this->assertSame('bob', $codeRow['login']);

        $first = $this->exchange($code);
        $this->assertSame(2, $this->accounts->issued[0]['company']);
        $this->assertSame('bob', $this->accounts->issued[0]['login']);
        $jti = $this->accounts->issued[0]['jti'];
        $row = $this->refreshRow($first['refresh_token']);
        $this->assertSame(2, $row['company']);
        $this->assertSame('bob', $row['login']);

        $this->refresh($first['refresh_token']);
        $this->assertSame([['company' => 2, 'jti' => $jti]], $this->accounts->revocationChecks);
        $this->assertSame(2, $this->accounts->issued[1]['company']);
        $this->assertSame([['company' => 2, 'jti' => $jti]], $this->accounts->revoked);
    }

    public function testRefreshIssueFailureChangesNothing(): void
    {
        $first = $this->exchange($this->code());
        $firstJti = $this->accounts->issued[0]['jti'];

        $this->accounts->beforeIssue = function () {
            throw new \RuntimeException('database gone');
        };
        $e = $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'server_error', 500);
        $this->assertStringNotContainsString('database gone', $e->description());
        $this->assertSame([], $this->accounts->revokedIds());

        // Nothing changed: the client retries with the same refresh token.
        $second = $this->refresh($first['refresh_token']);
        $this->assertSame($this->accounts->issued[1]['token'], $second['access_token']);
        $this->assertSame([$firstJti], $this->accounts->revokedIds());
    }

    public function testRefreshIssueOAuthErrorPassesThrough(): void
    {
        $first = $this->exchange($this->code());
        $this->accounts->beforeIssue = function () {
            throw new OAuthError('invalid_grant', 'refused');
        };
        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');
        $this->assertSame([], $this->accounts->revokedIds());
        $this->refresh($first['refresh_token']);
        $this->assertCount(2, $this->accounts->issued);
    }

    public function testRefreshRaceLoserRevokesItsNewToken(): void
    {
        $first = $this->exchange($this->code());
        $firstJti = $this->accounts->issued[0]['jti'];

        // A second request with the same refresh token completes while this
        // one is minting its access token.
        $winner = null;
        $this->accounts->beforeIssue = function () use ($first, &$winner) {
            $winner = $this->refresh($first['refresh_token']);
        };
        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');

        $this->assertCount(3, $this->accounts->issued);
        $winnerJti = $this->accounts->issued[1]['jti'];
        $loserJti = $this->accounts->issued[2]['jti'];
        $this->assertSame($this->accounts->issued[1]['token'], $winner['access_token']);
        $this->assertSame([$firstJti, $loserJti], $this->accounts->revokedIds());
        // The loser's own refresh row, saved before it lost, is gone again.
        $this->assertNotContains($loserJti, array_column($this->store->graphQlTokens(), 'graphql_jti'));

        // The winner's refresh token still works.
        $this->refresh($winner['refresh_token']);
        $this->assertContains($winnerJti, $this->accounts->revokedIds());
    }

    public function testRefreshSaveFailureLeavesTheOldRefreshTokenUsable(): void
    {
        $first = $this->exchange($this->code());
        $firstJti = $this->accounts->issued[0]['jti'];

        $this->store->beforeSaveRefresh = function () {
            throw new \RuntimeException('disk full');
        };
        $e = $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'server_error', 500);
        $this->assertStringNotContainsString('disk full', $e->description());

        // The access token minted for the failed refresh is withdrawn; the
        // old one, and the old refresh row, are untouched.
        $this->assertSame([$this->accounts->issued[1]['jti']], $this->accounts->revokedIds());
        $this->assertNull($this->refreshRow($first['refresh_token'])['rotated_at']);

        $this->store->beforeSaveRefresh = null;
        $second = $this->refresh($first['refresh_token']);
        $this->assertSame($this->accounts->issued[2]['token'], $second['access_token']);
        $this->assertContains($firstJti, $this->accounts->revokedIds());
    }

    public function testRefreshSavesTheNewRowBeforeRotatingTheOld(): void
    {
        $first = $this->exchange($this->code());
        $oldHash = hash('sha256', $first['refresh_token']);

        $rotatedWhenSaving = 'not called';
        $this->store->beforeSaveRefresh = function () use ($oldHash, &$rotatedWhenSaving) {
            $rotatedWhenSaving = $this->store->findRefreshByHash($oldHash)['rotated_at'];
        };
        $this->refresh($first['refresh_token']);

        $this->assertNull($rotatedWhenSaving);
        $this->assertNotNull($this->store->findRefreshByHash($oldHash)['rotated_at']);
    }

    public function testCodeExchangeSaveFailureRevokesTheNewToken(): void
    {
        $code = $this->code();
        $this->store->beforeSaveRefresh = function () {
            throw new \RuntimeException('disk full');
        };
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code);
        }, 'server_error', 500);
        $this->assertSame([$this->accounts->issued[0]['jti']], $this->accounts->revokedIds());
    }

    public function testRefreshFailsWhenApiAccessWithdrawn(): void
    {
        $first = $this->exchange($this->code());
        $this->accounts->setApiAccess(self::COMPANY, self::LOGIN, false);

        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');
        $this->assertCount(1, $this->accounts->issued);
        $this->assertNull($this->refreshRow($first['refresh_token'])['rotated_at']);
    }

    public function testCodeExchangeFailsWhenApiAccessWithdrawn(): void
    {
        $code = $this->code();
        $this->accounts->setApiAccess(self::COMPANY, self::LOGIN, false);
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code);
        }, 'invalid_grant');
        $this->assertSame([], $this->accounts->issued);
    }

    public function testCodeExchangeFailsForADeactivatedUser(): void
    {
        $code = $this->code();
        $this->accounts->setActive(self::COMPANY, self::LOGIN, false);
        $this->expectOAuthError(function () use ($code) {
            $this->exchange($code);
        }, 'invalid_grant');
        $this->assertSame([], $this->accounts->issued);
    }

    public function testRefreshWrongClient(): void
    {
        $other = $this->clients->register(['redirect_uris' => [self::REDIRECT]], self::IP);
        $first = $this->exchange($this->code());

        $this->expectOAuthError(function () use ($first, $other) {
            $this->refresh($first['refresh_token'], $other->clientId());
        }, 'invalid_grant');
        $this->assertCount(1, $this->accounts->issued);
    }

    public function testUnknownRefreshToken(): void
    {
        $this->expectOAuthError(function () {
            $this->refresh(str_repeat('c', 43));
        }, 'invalid_grant');
    }

    public function testForgetAccountDropsRefreshTokens(): void
    {
        $this->accounts->addUser(2, self::LOGIN);
        $first = $this->exchange($this->code());
        $sameLoginOtherCompany = $this->exchange($this->code('write', 2, self::LOGIN));

        $this->server->forgetAccount(self::COMPANY, self::LOGIN);
        $this->expectOAuthError(function () use ($first) {
            $this->refresh($first['refresh_token']);
        }, 'invalid_grant');
        $this->assertArrayHasKey('access_token', $this->refresh($sameLoginOtherCompany['refresh_token']));
    }

    public function testTokenRequestPrunesExpiredRows(): void
    {
        $base = [
            'family' => str_repeat('f', 32), 'client_id' => 'c', 'company' => 1, 'login' => 'x',
            'scope' => 'read', 'graphql_jti' => str_repeat('0', 32), 'rotated_at' => null,
            'revoked_at' => null, 'created_at' => 0,
        ];
        $now = $this->clock->now();
        $this->store->saveRefresh($base + ['token_hash' => 'expired', 'expires_at' => $now]);
        $this->store->saveRefresh($base + ['token_hash' => 'live', 'expires_at' => $now + 1]);
        $this->store->saveCode([
            'code_hash' => 'old', 'client_id' => 'c', 'company' => 1, 'login' => 'x',
            'redirect_uri' => self::REDIRECT, 'code_challenge' => self::CHALLENGE, 'scope' => 'read',
            'resource' => null, 'expires_at' => $now - AuthorizationServer::USED_CODE_KEPT_SECONDS - 1,
            'used_at' => null,
        ]);

        $this->expectOAuthError(function () {
            $this->refresh(str_repeat('c', 43));
        }, 'invalid_grant');

        $this->assertNull($this->store->findRefreshByHash('expired'));
        $this->assertNotNull($this->store->findRefreshByHash('live'));
        $this->assertNull($this->store->findCode('old'));
    }

    public function testAuthorizeValidationUnknownClientNotRedirectable(): void
    {
        $e = $this->expectOAuthError(function () {
            $this->server->validateAuthorizeRequest($this->authorizeQuery(['client_id' => 'nope']), self::IP);
        }, 'invalid_client', 401);
        $this->assertFalse($e->redirectable());
    }

    public function testAuthorizeMissingClientNotRedirectable(): void
    {
        $query = $this->authorizeQuery();
        unset($query['client_id']);
        $e = $this->expectOAuthError(function () use ($query) {
            $this->server->validateAuthorizeRequest($query, self::IP);
        }, 'invalid_request');
        $this->assertFalse($e->redirectable());
    }

    public function testAuthorizeBadRedirectNotRedirectable(): void
    {
        $e = $this->expectOAuthError(function () {
            $this->server->validateAuthorizeRequest(
                $this->authorizeQuery(['redirect_uri' => 'https://evil.example/cb']),
                self::IP
            );
        }, 'invalid_request');
        $this->assertFalse($e->redirectable());
    }

    public function testAuthorizeMissingChallengeIsRedirectable(): void
    {
        $query = $this->authorizeQuery();
        unset($query['code_challenge']);
        $e = $this->expectOAuthError(function () use ($query) {
            $this->server->validateAuthorizeRequest($query, self::IP);
        }, 'invalid_request');
        $this->assertTrue($e->redirectable());
    }

    public function testAuthorizePlainChallengeMethodIsRedirectable(): void
    {
        $e = $this->expectOAuthError(function () {
            $this->server->validateAuthorizeRequest(
                $this->authorizeQuery(['code_challenge_method' => 'plain']),
                self::IP
            );
        }, 'invalid_request');
        $this->assertTrue($e->redirectable());
    }

    public function testAuthorizeWrongResponseType(): void
    {
        $e = $this->expectOAuthError(function () {
            $this->server->validateAuthorizeRequest($this->authorizeQuery(['response_type' => 'token']), self::IP);
        }, 'unsupported_response_type');
        $this->assertTrue($e->redirectable());
    }

    public function testAuthorizeWrongResource(): void
    {
        $e = $this->expectOAuthError(function () {
            $this->server->validateAuthorizeRequest(
                $this->authorizeQuery(['resource' => 'https://x.example/mcp']),
                self::IP
            );
        }, 'invalid_target');
        $this->assertTrue($e->redirectable());
    }

    public function testUnsupportedGrantType(): void
    {
        $this->expectOAuthError(function () {
            $this->server->token(['grant_type' => 'password', 'client_id' => $this->client->clientId()]);
        }, 'unsupported_grant_type');
    }

    public function testMissingGrantType(): void
    {
        $this->expectOAuthError(function () {
            $this->server->token(['client_id' => $this->client->clientId()]);
        }, 'invalid_request');
    }

    public function testIssuingLeavesOtherConnectionsAlone(): void
    {
        $this->exchange($this->code());
        $this->exchange($this->code());
        $this->assertSame([], $this->accounts->revokedIds());
        $this->assertCount(2, $this->accounts->issued);
    }

    public function testErrorToArray(): void
    {
        $e = new OAuthError('invalid_grant', 'Nope');
        $this->assertSame(['error' => 'invalid_grant', 'error_description' => 'Nope'], $e->toArray());
        $this->assertFalse($e->redirectable());
    }
}
