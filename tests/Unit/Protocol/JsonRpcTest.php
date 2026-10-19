<?php

namespace FA\Mcp\Tests\Unit\Protocol;

use FA\Mcp\Protocol\Envelope;
use FA\Mcp\Protocol\JsonRpc;
use FA\Mcp\Protocol\RpcError;
use PHPUnit\Framework\TestCase;

class JsonRpcTest extends TestCase
{
    public function testResult(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['a' => 1]],
            JsonRpc::result(3, ['a' => 1])
        );
    }

    public function testErrorOmitsNullData(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']],
            JsonRpc::error(null, new RpcError(RpcError::PARSE, 'Parse error'))
        );
    }

    public function testErrorCarriesData(): void
    {
        $e = new RpcError(RpcError::UNSUPPORTED_VERSION, 'Unsupported', ['requested' => 'v']);
        $body = JsonRpc::error('x', $e);
        $this->assertSame('x', $body['id']);
        $this->assertSame(['requested' => 'v'], $body['error']['data']);
    }

    public function testRpcErrorAccessors(): void
    {
        $e = new RpcError(RpcError::INVALID_PARAMS, 'bad', ['path' => '/a']);
        $this->assertSame(-32602, $e->rpcCode());
        $this->assertSame('bad', $e->getMessage());
        $this->assertSame(['path' => '/a'], $e->data());
    }

    public function testEnvelopeRequest(): void
    {
        $env = Envelope::fromMessage(['jsonrpc' => '2.0', 'id' => 'r1', 'method' => 'tools/list',
            'params' => ['cursor' => 'c']]);
        $this->assertFalse($env->isNotification());
        $this->assertSame('r1', $env->id());
        $this->assertSame('tools/list', $env->method());
        $this->assertSame(['cursor' => 'c'], $env->params());
    }

    public function testEnvelopeNotificationHasNoIdKey(): void
    {
        $env = Envelope::fromMessage(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertTrue($env->isNotification());
        $this->assertSame([], $env->params());
    }

    public function testEnvelopeNullIdIsStillARequest(): void
    {
        $this->assertFalse(Envelope::fromMessage(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping'])
            ->isNotification());
    }

    /** @dataProvider invalidMessages */
    public function testEnvelopeRejectsInvalid(array $message): void
    {
        try {
            Envelope::fromMessage($message);
            $this->fail('expected RpcError');
        } catch (RpcError $e) {
            $this->assertSame(RpcError::INVALID_REQUEST, $e->rpcCode());
        }
    }

    public function invalidMessages(): array
    {
        return [
            'response' => [['jsonrpc' => '2.0', 'id' => 1, 'result' => []]],
            'error response' => [['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => 1]]],
            'no jsonrpc' => [['id' => 1, 'method' => 'ping']],
            'wrong jsonrpc' => [['jsonrpc' => 2, 'id' => 1, 'method' => 'ping']],
            'no method' => [['jsonrpc' => '2.0', 'id' => 1]],
            'method not string' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => []]],
            'params not structured' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => 'x']],
            'id an object' => [['jsonrpc' => '2.0', 'id' => ['a' => 1], 'method' => 'ping']],
        ];
    }

    public function testReplyIdIsTheMessageIdOnlyWhenValid(): void
    {
        $this->assertSame(4, Envelope::replyId(['id' => 4]));
        $this->assertSame('s', Envelope::replyId(['id' => 's']));
        $this->assertNull(Envelope::replyId(['id' => [1]]));
        $this->assertNull(Envelope::replyId([]));
    }
}
