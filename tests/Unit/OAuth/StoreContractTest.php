<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\OAuth\Store;
use PHPUnit\Framework\TestCase;

/**
 * What every Store must do, run against InMemoryStore (unit) and PdoStore
 * (integration), so the unit suite's stand-in keeps the database's semantics.
 * Rows read back exactly as saved: times as Unix seconds (int), nullable
 * times and resource as null, ids and company as int.
 */
abstract class StoreContractTest extends TestCase
{
    protected const T = 1700000000;

    protected Store $store;

    abstract protected function createStore(): Store;

    protected function setUp(): void
    {
        $this->store = $this->createStore();
    }

    protected function clientRow(string $id, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $id,
            'kind' => 'dcr',
            'client_name' => 'Claude ✓',
            'redirect_uris' => '["https://claude.ai/api/mcp/auth_callback"]',
            'created_at' => self::T,
            'last_used_at' => null,
            'fetched_at' => null,
            'source_ip' => '203.0.113.1',
        ], $overrides);
    }

    protected function codeRow(string $hash, array $overrides = []): array
    {
        return array_merge([
            'code_hash' => $hash,
            'client_id' => 'c1',
            'company' => 2,
            'login' => 'alice',
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'scope' => 'write',
            'resource' => 'https://fa.example.com/modules/mcp/',
            'expires_at' => self::T + 60,
            'used_at' => null,
        ], $overrides);
    }

    protected function refreshRow(string $hash, array $overrides = []): array
    {
        return array_merge([
            'token_hash' => $hash,
            'family' => str_repeat('f', 32),
            'client_id' => 'c1',
            'company' => 2,
            'login' => 'alice',
            'scope' => 'read',
            'graphql_jti' => str_repeat('a', 32),
            'expires_at' => self::T + 1000,
            'rotated_at' => null,
            'revoked_at' => null,
            'created_at' => self::T,
        ], $overrides);
    }

    /** Same keys and strictly the same values, in any key order. */
    protected function assertRow(array $expected, ?array $actual): void
    {
        $this->assertNotNull($actual);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    public function testClientRoundTripAndReplace(): void
    {
        $this->assertNull($this->store->findClient('c1'));
        $row = $this->clientRow('c1');
        $this->store->saveClient($row);
        $this->assertRow($row, $this->store->findClient('c1'));

        $replaced = $this->clientRow('c1', [
            'kind' => 'cimd', 'client_name' => '', 'redirect_uris' => '[]',
            'last_used_at' => self::T + 5, 'fetched_at' => self::T + 6, 'source_ip' => '2001:db8::1',
        ]);
        $this->store->saveClient($replaced);
        $this->assertRow($replaced, $this->store->findClient('c1'));
    }

    public function testTouchClient(): void
    {
        $this->store->saveClient($this->clientRow('c1'));
        $this->store->touchClient('c1', self::T + 30);
        $this->assertSame(self::T + 30, $this->store->findClient('c1')['last_used_at']);
        $this->store->touchClient('missing', self::T);
        $this->assertNull($this->store->findClient('missing'));
    }

    public function testCountClientsFromIpSince(): void
    {
        $this->store->saveClient($this->clientRow('a', ['created_at' => self::T - 10]));
        $this->store->saveClient($this->clientRow('b', ['created_at' => self::T]));
        $this->store->saveClient($this->clientRow('c', ['created_at' => self::T + 10]));
        $this->store->saveClient($this->clientRow('d', ['source_ip' => '198.51.100.1']));
        $this->store->saveClient($this->clientRow('https://x.example/c.json', ['kind' => 'cimd']));

        $this->assertSame(2, $this->store->countClientsFromIpSince('203.0.113.1', self::T, 'dcr'));
        $this->assertSame(1, $this->store->countClientsFromIpSince('203.0.113.1', self::T, 'cimd'));
        $this->assertSame(1, $this->store->countClientsFromIpSince('198.51.100.1', self::T, 'dcr'));
        $this->assertSame(0, $this->store->countClientsFromIpSince('192.0.2.1', self::T, 'dcr'));
    }

    public function testPruneUnusedClients(): void
    {
        $old = self::T - 100;
        $this->store->saveClient($this->clientRow('idle', ['created_at' => $old]));
        $this->store->saveClient($this->clientRow('used', ['created_at' => $old, 'last_used_at' => self::T]));
        $this->store->saveClient($this->clientRow('held', ['created_at' => $old]));
        $this->store->saveClient($this->clientRow('rotated', ['created_at' => $old]));
        $this->store->saveRefresh($this->refreshRow('h', ['client_id' => 'held', 'expires_at' => self::T + 1]));
        $this->store->saveRefresh($this->refreshRow('r', [
            'client_id' => 'rotated', 'expires_at' => self::T + 1, 'rotated_at' => self::T - 1,
        ]));
        $this->store->saveCode($this->codeRow('oldcode', ['expires_at' => self::T - 51]));
        $this->store->saveCode($this->codeRow('newcode', ['expires_at' => self::T - 50]));

        $this->store->pruneUnusedClients(self::T - 50, self::T);

        $this->assertNull($this->store->findClient('idle'));
        $this->assertNotNull($this->store->findClient('used'));
        $this->assertNotNull($this->store->findClient('held'));
        $this->assertNull($this->store->findClient('rotated'));
        $this->assertNull($this->store->findCode('oldcode'));
        $this->assertNotNull($this->store->findCode('newcode'));
    }

    public function testPruneExpiredRefreshKeepsRowsUntilTheirExpiry(): void
    {
        $this->store->saveRefresh($this->refreshRow('a', ['expires_at' => self::T]));
        $this->store->saveRefresh($this->refreshRow('b', ['expires_at' => self::T + 1]));
        $this->store->saveRefresh($this->refreshRow('c', ['expires_at' => self::T - 5, 'revoked_at' => self::T - 9]));

        $this->store->pruneExpiredRefresh(self::T);
        $this->assertNull($this->store->findRefreshByHash('a'));
        $this->assertNull($this->store->findRefreshByHash('c'));
        $this->assertNotNull($this->store->findRefreshByHash('b'));
    }

    public function testPruneExpiredRefreshHonoursTheLimit(): void
    {
        foreach (['a', 'b', 'c'] as $hash) {
            $this->store->saveRefresh($this->refreshRow($hash, ['expires_at' => self::T - 1]));
        }
        $this->store->pruneExpiredRefresh(self::T, 2);
        $left = array_filter(['a', 'b', 'c'], function (string $hash): bool {
            return $this->store->findRefreshByHash($hash) !== null;
        });
        $this->assertCount(1, $left);
    }

    public function testPruneExpiredCodes(): void
    {
        $this->store->saveCode($this->codeRow('a', ['expires_at' => self::T - 1]));
        $this->store->saveCode($this->codeRow('b', ['expires_at' => self::T - 1, 'used_at' => self::T - 30]));
        $this->store->saveCode($this->codeRow('c', ['expires_at' => self::T - 1]));
        $this->store->saveCode($this->codeRow('d', ['expires_at' => self::T]));

        $this->store->pruneExpiredCodes(self::T, 2);
        $gone = array_filter(['a', 'b', 'c'], function (string $hash): bool {
            return $this->store->findCode($hash) === null;
        });
        $this->assertCount(2, $gone);
        $this->assertNotNull($this->store->findCode('d'));

        $this->store->pruneExpiredCodes(self::T);
        $this->assertNull($this->store->findCode('a'));
        $this->assertNull($this->store->findCode('b'));
        $this->assertNull($this->store->findCode('c'));
        $this->assertNotNull($this->store->findCode('d'));
    }

    public function testCodeRoundTripAndSingleConsumption(): void
    {
        $this->assertNull($this->store->findCode('h1'));
        $row = $this->codeRow('h1');
        $this->store->saveCode($row);
        $this->assertRow($row, $this->store->findCode('h1'));

        $noResource = $this->codeRow('h2', ['resource' => null]);
        $this->store->saveCode($noResource);
        $this->assertRow($noResource, $this->store->findCode('h2'));

        $this->assertTrue($this->store->consumeCode('h1', self::T + 1));
        $this->assertFalse($this->store->consumeCode('h1', self::T + 2));
        $this->assertSame(self::T + 1, $this->store->findCode('h1')['used_at']);
        $this->assertFalse($this->store->consumeCode('missing', self::T));
    }

    public function testRefreshRoundTrip(): void
    {
        $this->assertNull($this->store->findRefreshByHash('x'));
        $row = $this->refreshRow('x');
        $id = $this->store->saveRefresh($row);
        $other = $this->store->saveRefresh($this->refreshRow('y'));
        $this->assertNotSame($id, $other);
        $this->assertRow($row + ['id' => $id], $this->store->findRefreshByHash('x'));
    }

    public function testRotateRefreshOnce(): void
    {
        $id = $this->store->saveRefresh($this->refreshRow('x'));
        $this->assertTrue($this->store->rotateRefresh($id, self::T + 1));
        $this->assertFalse($this->store->rotateRefresh($id, self::T + 2));
        $this->assertSame(self::T + 1, $this->store->findRefreshByHash('x')['rotated_at']);

        $revoked = $this->store->saveRefresh($this->refreshRow('y', ['revoked_at' => self::T]));
        $this->assertFalse($this->store->rotateRefresh($revoked, self::T + 1));
        $this->assertFalse($this->store->rotateRefresh(999999, self::T));
    }

    public function testRevokeFamily(): void
    {
        $family = str_repeat('1', 32);
        $this->store->saveRefresh($this->refreshRow('a', ['family' => $family, 'revoked_at' => self::T - 9]));
        $this->store->saveRefresh($this->refreshRow('b', ['family' => $family, 'rotated_at' => self::T - 5]));
        $this->store->saveRefresh($this->refreshRow('c', ['family' => str_repeat('2', 32)]));

        $rows = $this->store->revokeFamily($family, self::T);

        $byHash = array_column($rows, 'revoked_at', 'token_hash');
        ksort($byHash);
        $this->assertSame(['a' => self::T - 9, 'b' => self::T], $byHash);
        $this->assertSame(self::T, $this->store->findRefreshByHash('b')['revoked_at']);
        $this->assertNull($this->store->findRefreshByHash('c')['revoked_at']);
        $this->assertSame([], $this->store->revokeFamily(str_repeat('3', 32), self::T));
    }

    public function testDeleteRefreshForAccountIsPerCompanyAndLogin(): void
    {
        $this->store->saveRefresh($this->refreshRow('a', ['company' => 1, 'login' => 'alice']));
        $this->store->saveRefresh($this->refreshRow('b', ['company' => 2, 'login' => 'alice']));
        $this->store->saveRefresh($this->refreshRow('c', ['company' => 1, 'login' => 'bob']));

        $this->store->deleteRefreshForAccount(1, 'alice');
        $this->assertNull($this->store->findRefreshByHash('a'));
        $this->assertNotNull($this->store->findRefreshByHash('b'));
        $this->assertNotNull($this->store->findRefreshByHash('c'));
    }

    public function testDeleteRefresh(): void
    {
        $id = $this->store->saveRefresh($this->refreshRow('a'));
        $this->store->saveRefresh($this->refreshRow('b'));
        $this->store->deleteRefresh($id);
        $this->assertNull($this->store->findRefreshByHash('a'));
        $this->assertNotNull($this->store->findRefreshByHash('b'));
    }

    public function testGraphQlTokensListsEachTokenOnce(): void
    {
        $this->store->saveRefresh($this->refreshRow('a', ['company' => 1, 'graphql_jti' => str_repeat('b', 32)]));
        $this->store->saveRefresh($this->refreshRow('b', ['company' => 1, 'graphql_jti' => str_repeat('a', 32)]));
        $this->store->saveRefresh($this->refreshRow('c', ['company' => 0, 'graphql_jti' => str_repeat('c', 32)]));
        $this->store->saveRefresh($this->refreshRow('d', ['company' => 0, 'graphql_jti' => str_repeat('c', 32)]));

        $this->assertSame(
            [
                ['company' => 0, 'graphql_jti' => str_repeat('c', 32)],
                ['company' => 1, 'graphql_jti' => str_repeat('a', 32)],
                ['company' => 1, 'graphql_jti' => str_repeat('b', 32)],
            ],
            $this->store->graphQlTokens()
        );
    }
}
