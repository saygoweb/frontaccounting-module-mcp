<?php

namespace FA\Mcp\Tests\Unit\Protocol;

use FA\Mcp\Http\Json;
use FA\Mcp\Protocol\HttpOutcome;
use FA\Mcp\Protocol\HttpStatusException;
use FA\Mcp\Protocol\McpServer;
use FA\Mcp\Protocol\RpcError;
use FA\Mcp\Protocol\Versions;
use FA\Mcp\Tests\Support\FakeToolProvider;
use PHPUnit\Framework\TestCase;

class McpServerTest extends TestCase
{
    private const SERVER_INFO = ['name' => 'frontaccounting-mcp', 'version' => '0.1.0'];

    /** @var FakeToolProvider */
    private $tools;

    protected function setUp(): void
    {
        $this->tools = new FakeToolProvider([
            ['name' => 'list_customers', 'description' => 'Lists customers',
                'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'get_status', 'description' => 'Status',
                'inputSchema' => ['type' => 'object'], 'annotations' => ['readOnlyHint' => true]],
        ]);
    }

    private function server(bool $debug = false): McpServer
    {
        return new McpServer('frontaccounting-mcp', '0.1.0', 'test instructions', $debug);
    }

    private function legacy(array $msg, array $headers = []): HttpOutcome
    {
        return $this->server()->handle(Json::encode($msg), $headers, $this->tools);
    }

    /**
     * A 2026-07-28 request: the _meta version and the three headers are filled
     * in to agree with the message, and $extraHeaders then override them (a
     * null value removes that header).
     */
    private function modern(array $msg, array $extraHeaders = []): HttpOutcome
    {
        if (!isset($msg['params']['_meta'][Versions::META_VERSION])) {
            $msg['params']['_meta'][Versions::META_VERSION] = Versions::MODERN;
        }
        $headers = [
            'mcp-protocol-version' => $msg['params']['_meta'][Versions::META_VERSION],
            'mcp-method' => $msg['method'],
        ];
        if ($msg['method'] === 'tools/call' && isset($msg['params']['name'])) {
            $headers['mcp-name'] = $msg['params']['name'];
        }
        foreach ($extraHeaders as $name => $value) {
            if ($value === null) {
                unset($headers[$name]);
            } else {
                $headers[$name] = $value;
            }
        }
        return $this->server()->handle(Json::encode($msg), $headers, $this->tools);
    }

    private function assertRpcError(HttpOutcome $out, int $status, int $code): array
    {
        $this->assertSame($status, $out->status());
        $body = $out->body();
        $this->assertSame('2.0', $body['jsonrpc']);
        $this->assertArrayNotHasKey('result', $body);
        $this->assertSame($code, $body['error']['code']);
        $this->assertIsString($body['error']['message']);
        return $body['error'];
    }

    public function testLegacyInitializeWithHeaderSucceeds(): void
    {
        $out = $this->server()->handle(
            Json::encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'claude-ai', 'version' => '1']]]),
            ['mcp-protocol-version' => '2025-06-18'],
            $this->tools
        );
        $this->assertSame(200, $out->status());
        $this->assertSame(1, $out->body()['id']);
        $this->assertSame('2025-06-18', $out->body()['result']['protocolVersion']);
        $this->assertSame(['listChanged' => false], $out->body()['result']['capabilities']['tools']);
        $this->assertSame(self::SERVER_INFO, $out->body()['result']['serverInfo']);
        $this->assertSame('test instructions', $out->body()['result']['instructions']);
        $this->assertArrayNotHasKey('resultType', $out->body()['result']);
        $this->assertArrayNotHasKey('mcp-session-id', array_change_key_case($out->headers()));
    }

    public function testInitializeUnknownVersionAnswersLatestLegacy(): void
    {
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2024-11-05', 'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'old', 'version' => '1']]]);
        $this->assertSame(200, $out->status());
        $this->assertSame('2025-11-25', $out->body()['result']['protocolVersion']);
    }

    public function testNotificationIs202NoBody(): void
    {
        $out = $this->legacy(
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['mcp-protocol-version' => '2025-06-18']
        );
        $this->assertSame(202, $out->status());
        $this->assertNull($out->body());
    }

    public function testParseErrorIs400(): void
    {
        $out = $this->server()->handle('{"jsonrpc":"2.0",', [], $this->tools);
        $this->assertRpcError($out, 400, RpcError::PARSE);
        $this->assertArrayHasKey('id', $out->body());
        $this->assertNull($out->body()['id']);
    }

    public function testBatchRejected(): void
    {
        $out = $this->server()->handle(Json::encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ]), [], $this->tools);
        $error = $this->assertRpcError($out, 400, RpcError::INVALID_REQUEST);
        $this->assertSame('Batches are not supported', $error['message']);
        $this->assertNull($out->body()['id']);
    }

    public function testResponseMessageRejected(): void
    {
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 7, 'result' => new \stdClass()]);
        $this->assertRpcError($out, 400, RpcError::INVALID_REQUEST);
    }

    public function testWrongJsonRpcVersionRejected(): void
    {
        $out = $this->legacy(['jsonrpc' => '1.0', 'id' => 3, 'method' => 'ping']);
        $this->assertRpcError($out, 400, RpcError::INVALID_REQUEST);
        $this->assertSame(3, $out->body()['id']);
    }

    public function testNonStringMethodRejected(): void
    {
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 3, 'method' => 5]);
        $this->assertRpcError($out, 400, RpcError::INVALID_REQUEST);
    }

    public function testLegacyUnknownMethodIs200MethodNotFound(): void
    {
        $out = $this->legacy(
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list'],
            ['mcp-protocol-version' => '2025-11-25']
        );
        $this->assertRpcError($out, 200, RpcError::METHOD_NOT_FOUND);
        $this->assertSame(4, $out->body()['id']);
    }

    public function testModernUnknownMethodIs404(): void
    {
        $out = $this->modern(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/list', 'params' => []]);
        $this->assertRpcError($out, 404, RpcError::METHOD_NOT_FOUND);
    }

    public function testModernDiscover(): void
    {
        $out = $this->modern(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'server/discover', 'params' => []]);
        $this->assertSame(200, $out->status());
        $this->assertSame('a', $out->body()['id']);
        $result = $out->body()['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(Versions::SUPPORTED, $result['supportedVersions']);
        $this->assertEquals(new \stdClass(), $result['capabilities']['tools']);
        $this->assertSame('test instructions', $result['instructions']);
        $this->assertSame(self::SERVER_INFO, $result['_meta'][Versions::META_SERVER_INFO]);
        $this->assertSame(3600000, $result['ttlMs']);
        $this->assertSame('public', $result['cacheScope']);
        // The JSON a client sees has an object, not an array, for the empty capability.
        $this->assertStringContainsString('"tools":{}', Json::encode($out->body()));
    }

    public function testLegacyDiscoverAlsoCarriesServerInfo(): void
    {
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'server/discover', 'params' => []]);
        $this->assertSame(200, $out->status());
        $result = $out->body()['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(Versions::SUPPORTED, $result['supportedVersions']);
        $this->assertSame(self::SERVER_INFO, $result['_meta'][Versions::META_SERVER_INFO]);
    }

    public function testModernToolsListHasCacheFieldsAndDeterministicOrder(): void
    {
        $out = $this->modern(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []]);
        $this->assertSame(200, $out->status());
        $result = $out->body()['result'];
        $this->assertSame(['list_customers', 'get_status'], array_column($result['tools'], 'name'));
        $this->assertSame(300000, $result['ttlMs']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(self::SERVER_INFO, $result['_meta'][Versions::META_SERVER_INFO]);
    }

    public function testLegacyToolsListHasNoModernFields(): void
    {
        $out = $this->legacy(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
            ['mcp-protocol-version' => '2025-06-18']
        );
        $this->assertSame(200, $out->status());
        $result = $out->body()['result'];
        $this->assertSame(['list_customers', 'get_status'], array_column($result['tools'], 'name'));
        $this->assertArrayNotHasKey('ttlMs', $result);
        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertArrayNotHasKey('_meta', $result);
    }

    public function testModernHeaderMismatchVersion(): void
    {
        $out = $this->modern(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
            ['mcp-protocol-version' => '2025-11-25']
        );
        $this->assertRpcError($out, 400, RpcError::HEADER_MISMATCH);
    }

    public function testModernMissingMcpMethodHeader(): void
    {
        $out = $this->modern(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
            ['mcp-method' => null]
        );
        $this->assertRpcError($out, 400, RpcError::HEADER_MISMATCH);
    }

    public function testModernUnsupportedVersion(): void
    {
        $out = $this->modern(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
            'params' => ['_meta' => [Versions::META_VERSION => '2030-01-01']]]);
        $error = $this->assertRpcError($out, 400, RpcError::UNSUPPORTED_VERSION);
        $this->assertSame(Versions::SUPPORTED, $error['data']['supported']);
        $this->assertSame('2030-01-01', $error['data']['requested']);
    }

    public function testModernToolsCallWithBase64McpName(): void
    {
        // Mcp-Name: =?base64?bGlzdF9jdXN0b21lcnM=?= ("list_customers") must match params.name
        $out = $this->modern(
            ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
            'params' => ['name' => 'list_customers', 'arguments' => ['limit' => 5]]],
            ['mcp-name' => '=?base64?bGlzdF9jdXN0b21lcnM=?=']
        );
        $this->assertSame(200, $out->status());
        $this->assertSame([['name' => 'list_customers', 'arguments' => ['limit' => 5]]], $this->tools->calls);
        $result = $out->body()['result'];
        $this->assertSame([['type' => 'text', 'text' => 'list_customers']], $result['content']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertArrayHasKey(Versions::META_SERVER_INFO, $result['_meta']);
    }

    public function testModernToolsCallMcpNameMismatch(): void
    {
        $out = $this->modern(
            ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
            'params' => ['name' => 'list_customers', 'arguments' => []]],
            ['mcp-name' => 'delete_customer']
        );
        $this->assertRpcError($out, 400, RpcError::HEADER_MISMATCH);
        $this->assertSame([], $this->tools->calls);
    }

    public function testModernToolsCallBadBase64McpNameIsMismatch(): void
    {
        $out = $this->modern(
            ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
            'params' => ['name' => 'list_customers', 'arguments' => []]],
            ['mcp-name' => '=?base64?!!not base64!!?=']
        );
        $this->assertRpcError($out, 400, RpcError::HEADER_MISMATCH);
    }

    public function testToolsCallArgumentsMustBeObject(): void
    {
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'list_customers', 'arguments' => [1, 2]]]);
        $this->assertRpcError($out, 200, RpcError::INVALID_PARAMS);
        $this->assertSame([], $this->tools->calls);
    }

    public function testToolsCallNameMustBeString(): void
    {
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 12]]);
        $this->assertRpcError($out, 200, RpcError::INVALID_PARAMS);
    }

    public function testToolsCallMissingArgumentsMeansEmpty(): void
    {
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'get_status']]);
        $this->assertSame(200, $out->status());
        $this->assertSame([['name' => 'get_status', 'arguments' => []]], $this->tools->calls);
    }

    public function testProviderRpcErrorPassesThrough(): void
    {
        $this->tools = new FakeToolProvider([], function () {
            throw new RpcError(RpcError::INVALID_PARAMS, 'Unknown tool: nope', ['path' => '/name']);
        });
        $out = $this->modern(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
            'params' => ['name' => 'nope', 'arguments' => []]]);
        $error = $this->assertRpcError($out, 200, RpcError::INVALID_PARAMS);
        $this->assertSame('Unknown tool: nope', $error['message']);
        $this->assertSame(['path' => '/name'], $error['data']);
        $this->assertSame(6, $out->body()['id']);
    }

    public function testHttpStatusExceptionBecomesOutcome(): void
    {
        $this->tools = new FakeToolProvider([], function () {
            throw new HttpStatusException(401, ['WWW-Authenticate' => 'Bearer resource_metadata="x"']);
        });
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
            'params' => ['name' => 'list_customers']]);
        $this->assertSame(401, $out->status());
        $this->assertNull($out->body());
        $this->assertSame(['WWW-Authenticate' => 'Bearer resource_metadata="x"'], $out->headers());
    }

    public function testHttpStatusExceptionCarriesBody(): void
    {
        $this->tools = new FakeToolProvider([], function () {
            throw new HttpStatusException(403, [], ['error' => 'insufficient_scope']);
        });
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
            'params' => ['name' => 'list_customers']]);
        $this->assertSame(403, $out->status());
        $this->assertSame(['error' => 'insufficient_scope'], $out->body());
    }

    public function testThrowableBecomesInternalErrorWithoutMessage(): void
    {
        $this->tools = new FakeToolProvider([], function () {
            throw new \LogicException('secret database detail');
        });
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
            'params' => ['name' => 'list_customers']]);
        $error = $this->assertRpcError($out, 200, RpcError::INTERNAL);
        $this->assertSame('Internal error', $error['message']);
        $this->assertStringNotContainsString('secret', Json::encode($out->body()));
    }

    public function testThrowableMessageShownOnlyInDebug(): void
    {
        $this->tools = new FakeToolProvider([], function () {
            throw new \LogicException('secret database detail');
        });
        $out = $this->server(true)->handle(Json::encode(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
            'params' => ['name' => 'list_customers']]), [], $this->tools);
        $error = $this->assertRpcError($out, 200, RpcError::INTERNAL);
        $this->assertSame('Internal error: secret database detail', $error['message']);
    }

    public function testLegacyUnsupportedHeaderVersionIs400(): void
    {
        $out = $this->legacy(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['mcp-protocol-version' => '2024-11-05']
        );
        $error = $this->assertRpcError($out, 400, RpcError::INVALID_REQUEST);
        $this->assertStringContainsString('Unsupported protocol version 2024-11-05', $error['message']);
        $this->assertStringContainsString('2025-03-26', $error['message']);
    }

    public function testMissingHeaderLegacyDefaultsTo20250326(): void
    {
        $out = $this->legacy(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-03-26', 'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'c', 'version' => '1']]]);
        $this->assertSame(200, $out->status());
        $this->assertSame('2025-03-26', $out->body()['result']['protocolVersion']);
    }

    public function testPingLegacy(): void
    {
        $out = $this->legacy(
            ['jsonrpc' => '2.0', 'id' => 'p', 'method' => 'ping'],
            ['mcp-protocol-version' => '2025-11-25']
        );
        $this->assertSame(200, $out->status());
        $this->assertSame('{"jsonrpc":"2.0","id":"p","result":{}}', Json::encode($out->body()));
    }

    public function testPingModernIsDecorated(): void
    {
        $out = $this->modern(['jsonrpc' => '2.0', 'id' => 'p', 'method' => 'ping', 'params' => []]);
        $this->assertSame(200, $out->status());
        $this->assertSame('complete', $out->body()['result']['resultType']);
    }
}
