<?php

namespace FA\Mcp\Protocol;

/** JSON-RPC 2.0 response bodies. */
final class JsonRpc
{
    public const VERSION = '2.0';

    /**
     * @param string|int|null $id
     * @param array|\stdClass $result a stdClass for an empty result, so it encodes as {}
     */
    public static function result($id, $result): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $result];
    }

    /** @param string|int|null $id */
    public static function error($id, RpcError $e): array
    {
        $error = ['code' => $e->rpcCode(), 'message' => $e->getMessage()];
        if ($e->data() !== null) {
            $error['data'] = $e->data();
        }
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'error' => $error];
    }
}
