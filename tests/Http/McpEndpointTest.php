<?php

namespace FA\Mcp\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * POST /modules/mcp/ through Apache with machine tokens from bin/fa-token,
 * the way Claude Code connects with a header (spec §5.2).
 */
class McpEndpointTest extends TestCase
{
    use McpClient;

    /** @var array{token: string, jti: string}|null */
    private static ?array $write = null;
    /** @var array{token: string, jti: string}|null */
    private static ?array $read = null;

    public static function setUpBeforeClass(): void
    {
        // modules/mcp/tests/Http: graphql is beside the module, FrontAccounting two levels up.
        $modules = dirname(__DIR__, 3);
        if (!is_file($modules . '/graphql/bin/fa-token') || !is_file(dirname($modules) . '/config_db.php')) {
            return; // setUp() skips
        }
        self::$write = self::issueToken(false);
        self::$read = self::issueToken(true);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$write, self::$read] as $issued) {
            if ($issued !== null) {
                self::revokeToken($issued['jti']);
            }
        }
    }

    protected function setUp(): void
    {
        if (self::$write === null) {
            $this->markTestSkipped('Needs the FrontAccounting CI image with the GraphQL module beside this one.');
        }
    }

    private static function writeToken(): string
    {
        return self::$write['token'];
    }

    private static function readToken(): string
    {
        return self::$read['token'];
    }

    /** @return array<int, array<string, mixed>> tools/list's tools */
    private function tools(string $token): array
    {
        $response = $this->rpc('tools/list', [], $token);
        $this->assertSame(200, $response['status'], $response['raw']);
        return $response['body']['result']['tools'];
    }

    /** @return string[] */
    private function toolNames(string $token): array
    {
        return array_column($this->tools($token), 'name');
    }

    public function testInitialize(): void
    {
        $response = $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'fa-mcp-tests', 'version' => '1'],
        ], self::writeToken());
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertStringStartsWith('application/json', $response['headers']['content-type']);
        $this->assertSame('2025-06-18', $response['body']['result']['protocolVersion']);
        $this->assertSame('frontaccounting-mcp', $response['body']['result']['serverInfo']['name']);
        $this->assertNotSame('', $response['body']['result']['instructions']);
    }

    public function testModernServerDiscover(): void
    {
        $response = $this->modern('server/discover', [], self::writeToken());
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertSame(
            ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'],
            $response['body']['result']['supportedVersions']
        );
    }

    public function testToolsListForWriteAndReadTokens(): void
    {
        $write = $this->toolNames(self::writeToken());
        $read = $this->toolNames(self::readToken());
        $this->assertContains('customer_create', $write);
        $this->assertContains('graphql_mutate', $write);
        $this->assertContains('customer_list', $read);
        $this->assertNotContains('customer_create', $read);
        $this->assertNotContains('graphql_mutate', $read);
        $this->assertSame([], array_values(array_diff($read, $write)), 'a read list is a subset of the write list');

        // Exactly: the read list is the write list less the tools that need
        // write, which are the ones not annotated read-only (every mutation
        // tool and graphql_mutate; every query tool is readOnlyHint).
        $writeTools = $this->tools(self::writeToken());
        $readOnly = array_column(array_filter($writeTools, function (array $tool): bool {
            return ($tool['annotations']['readOnlyHint'] ?? null) === true;
        }), 'name');
        $mutations = array_values(array_diff(array_column($writeTools, 'name'), $readOnly));
        $this->assertContains('customer_create', $mutations);
        $this->assertContains('graphql_mutate', $mutations);
        sort($readOnly);
        sort($read);
        $this->assertSame($readOnly, $read, 'read list = write list minus the write-only tools');
    }

    public function testModernToolsCallCustomerList(): void
    {
        $response = $this->modern(
            'tools/call',
            ['name' => 'customer_list', 'arguments' => ['query' => ['limit' => 2]]],
            self::readToken()
        );
        $this->assertSame(200, $response['status'], $response['raw']);
        $result = $response['body']['result'];
        $this->assertFalse($result['isError'], $response['raw']);
        $this->assertLessThanOrEqual(2, count($result['structuredContent']['data']));
    }

    public function testToolsCallCustomerCreate(): void
    {
        $ref = 'MCP' . strtoupper(bin2hex(random_bytes(3)));
        $name = 'MCP http test ' . $ref;
        $response = $this->rpc('tools/call', ['name' => 'customer_create', 'arguments' => ['input' => [[
            'name' => $name,
            'ref' => $ref,
            'salesTypeId' => '1',
            'paymentTermsId' => '1',
            'creditStatusId' => '1',
            // The demo company has auto_create_branch on.
            'branch' => [
                'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                'locationId' => 'DEF', 'shipperId' => '1',
            ],
        ]]]], self::writeToken());
        $this->assertSame(200, $response['status'], $response['raw']);
        $result = $response['body']['result'];
        $this->assertFalse($result['isError'], $response['raw']);
        $this->assertSame($name, $result['structuredContent']['data'][0]['name']);
    }

    public function testReadTokenCannotCallCustomerCreate(): void
    {
        $response = $this->rpc('tools/call', [
            'name' => 'customer_create',
            'arguments' => ['input' => [['name' => 'refused', 'ref' => 'NOPE']]],
        ], self::readToken());
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertSame(-32602, $response['body']['error']['code'], $response['raw']);
    }

    public function testNoTokenIs401WithResourceMetadata(): void
    {
        $response = $this->rpc('tools/list');
        $this->assertSame(401, $response['status'], $response['raw']);
        $this->assertSame(
            'Bearer resource_metadata="' . rtrim(self::mcpUrl(), '/') . '/.well-known/oauth-protected-resource"',
            $response['headers']['www-authenticate']
        );
    }

    public function testGarbageTokenIs401InvalidToken(): void
    {
        $response = $this->rpc('tools/list', [], 'not-a-token');
        $this->assertSame(401, $response['status'], $response['raw']);
        $this->assertStringContainsString('error="invalid_token"', $response['headers']['www-authenticate']);
        $this->assertStringContainsString('resource_metadata="', $response['headers']['www-authenticate']);
        $this->assertSame('invalid_token', $response['body']['error']);
    }

    public function testRevokedTokenIs401(): void
    {
        $issued = self::issueToken(false, 'mcp-http-test-revoked');
        $this->assertSame(200, $this->rpc('tools/list', [], $issued['token'])['status']);
        $this->assertTrue(self::revokeToken($issued['jti']));
        $response = $this->rpc('tools/list', [], $issued['token']);
        $this->assertSame(401, $response['status'], $response['raw']);
        $this->assertStringContainsString('error="invalid_token"', $response['headers']['www-authenticate']);
    }

    public function testGetIs405(): void
    {
        $response = $this->http('GET');
        $this->assertSame(405, $response['status'], $response['raw']);
        $this->assertIsArray($response['body']);
    }

    public function testIndexPhpIsTheEndpointToo(): void
    {
        $response = $this->http('POST', 'index.php', [
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2025-06-18',
            'Authorization' => 'Bearer ' . self::writeToken(),
        ], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
        $this->assertSame(200, $response['status'], $response['raw']);
    }

    public function testForeignOriginIs403(): void
    {
        $response = $this->rpc('tools/list', [], self::writeToken(), ['Origin' => 'https://evil.example']);
        $this->assertSame(403, $response['status'], $response['raw']);
    }

    public function testClaudeAiOriginGetsCors(): void
    {
        $response = $this->rpc('ping', [], self::writeToken(), ['Origin' => 'https://claude.ai']);
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertSame('https://claude.ai', $response['headers']['access-control-allow-origin']);
    }

    /** @dataProvider refusedPaths */
    public function testCodeConfigurationAndLibrariesAreNotServed(string $path): void
    {
        $this->assertSame(403, $this->http('GET', $path)['status']);
    }

    public function refusedPaths(): array
    {
        return [
            'vendor' => ['vendor/autoload.php'],
            'config' => ['config_mcp.php'],
            'source' => ['src/Config.php'],
            'hooks' => ['hooks.php'],
            'sql' => ['sql/update_1.0.sql'],
        ];
    }

    public function testOtherFilesReachTheAppAndAre404(): void
    {
        $response = $this->http('GET', 'composer.json');
        $this->assertSame(404, $response['status'], $response['raw']);
        $this->assertIsArray($response['body']);
    }
}
