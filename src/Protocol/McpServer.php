<?php

namespace FA\Mcp\Protocol;

use FA\Mcp\Http\Json;

/**
 * The dual-era, stateless MCP server of spec §6.1: one POSTed message in, one
 * HTTP outcome out. A request whose params._meta names a protocol version is
 * a 2026-07-28 ("modern") one and must repeat that version, its method and,
 * for tools/call, the tool name in Mcp-* headers. Anything else is a legacy
 * request, versioned by the MCP-Protocol-Version header alone. No session is
 * kept, so initialize is answered but remembers nothing.
 */
final class McpServer implements ProtocolServer
{
    /** Methods answered in either era; spec §6.1 explains why both handshakes are. */
    public const METHODS = ['initialize', 'ping', 'server/discover', 'tools/list', 'tools/call'];

    public const DISCOVER_TTL_MS = 3600000;
    public const TOOLS_LIST_TTL_MS = 300000;

    private string $serverName;
    private string $serverVersion;
    private string $instructions;
    private bool $debug;

    /** @param bool $debug the debug setting: an unexpected exception's message reaches the client */
    public function __construct(string $serverName, string $serverVersion, string $instructions, bool $debug = false)
    {
        $this->serverName = $serverName;
        $this->serverVersion = $serverVersion;
        $this->instructions = $instructions;
        $this->debug = $debug;
    }

    public function handle(string $rawBody, array $headers, ToolProvider $tools): HttpOutcome
    {
        $headers = array_change_key_case($headers, CASE_LOWER);

        $message = Json::decodeObject($rawBody);
        if ($message === null) {
            // decodeObject refuses a JSON array too; tell a batch apart from garbage.
            return is_array(json_decode($rawBody, true))
                ? self::failure(400, null, new RpcError(RpcError::INVALID_REQUEST, 'Batches are not supported'))
                : self::failure(400, null, new RpcError(RpcError::PARSE, 'Parse error'));
        }

        try {
            $request = Envelope::fromMessage($message);
        } catch (RpcError $e) {
            return self::failure(400, Envelope::replyId($message), $e);
        }

        // Nothing is sent back for a notification, whatever it announces.
        if ($request->isNotification()) {
            return new HttpOutcome(202, null);
        }
        $id = $request->id();
        $params = $request->params();

        $metaVersion = isset($params['_meta'][Versions::META_VERSION])
            && is_string($params['_meta'][Versions::META_VERSION])
            ? $params['_meta'][Versions::META_VERSION]
            : null;
        $modern = $metaVersion !== null;

        try {
            if ($modern) {
                $this->validateModern($request, $metaVersion, $headers);
            } else {
                $this->validateLegacy($headers);
            }
        } catch (RpcError $e) {
            return self::failure(400, $id, $e);
        }

        if (!in_array($request->method(), self::METHODS, true)) {
            return self::failure(
                $modern ? 404 : 200,
                $id,
                new RpcError(RpcError::METHOD_NOT_FOUND, 'Method not found: ' . $request->method())
            );
        }

        try {
            $result = $this->dispatch($request->method(), $params, $modern, $tools);
        } catch (RpcError $e) {
            return self::failure(200, $id, $e);
        } catch (HttpStatusException $e) {
            return new HttpOutcome($e->status(), $e->body(), $e->headers());
        } catch (\Throwable $e) {
            // The client learns only that something broke - unless debug is
            // on (spec §10); the detail always goes to FA's error log.
            error_log('frontaccounting-mcp: ' . get_class($e) . ' ' . $e->getMessage());
            $message = $this->debug ? 'Internal error: ' . $e->getMessage() : 'Internal error';
            return self::failure(200, $id, new RpcError(RpcError::INTERNAL, $message));
        }

        // server/discover is a modern method, so its result has the modern
        // shape (serverInfo in _meta) whichever era's handshake carried it.
        $decorate = $modern || $request->method() === 'server/discover';
        return new HttpOutcome(200, JsonRpc::result($id, $decorate ? $this->decorate($result) : $result));
    }

    /**
     * 2026-07-28 Streamable HTTP: the Mcp-* headers must repeat what the body
     * says, so an intermediary can route on them without parsing the body.
     *
     * @param array<string,string> $headers
     * @throws RpcError HEADER_MISMATCH or UNSUPPORTED_VERSION
     */
    private function validateModern(Envelope $request, string $version, array $headers): void
    {
        if (self::header($headers, 'mcp-protocol-version') !== $version) {
            throw new RpcError(RpcError::HEADER_MISMATCH, 'MCP-Protocol-Version header does not match _meta');
        }
        if (!Versions::isSupported($version)) {
            throw new RpcError(
                RpcError::UNSUPPORTED_VERSION,
                'Unsupported protocol version ' . $version,
                ['supported' => Versions::SUPPORTED, 'requested' => $version]
            );
        }
        if (self::header($headers, 'mcp-method') !== $request->method()) {
            throw new RpcError(RpcError::HEADER_MISMATCH, 'Mcp-Method header does not match method');
        }
        if ($request->method() === 'tools/call') {
            $name = self::decodeSentinel(self::header($headers, 'mcp-name'));
            $params = $request->params();
            // A non-string params.name is left for tools/call to refuse with -32602.
            if ($name === null || (isset($params['name']) && is_string($params['name']) && $name !== $params['name'])) {
                throw new RpcError(RpcError::HEADER_MISMATCH, 'Mcp-Name header does not match params.name');
            }
        }
    }

    /**
     * @param array<string,string> $headers
     * @throws RpcError INVALID_REQUEST for a version this server does not speak
     */
    private function validateLegacy(array $headers): void
    {
        $version = self::header($headers, 'mcp-protocol-version');
        if ($version !== null && !Versions::isSupported($version)) {
            throw new RpcError(
                RpcError::INVALID_REQUEST,
                'Unsupported protocol version ' . $version . '; supported: ' . implode(', ', Versions::SUPPORTED)
            );
        }
    }

    /**
     * @return array|\stdClass the method's result
     * @throws RpcError|HttpStatusException|\Throwable from the tool provider
     */
    private function dispatch(string $method, array $params, bool $modern, ToolProvider $tools)
    {
        switch ($method) {
            case 'initialize':
                $requested = isset($params['protocolVersion']) && is_string($params['protocolVersion'])
                    ? $params['protocolVersion'] : '';
                return [
                    'protocolVersion' => Versions::isSupported($requested) ? $requested : Versions::LATEST_LEGACY,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => $this->serverInfo(),
                    'instructions' => $this->instructions,
                ];
            case 'ping':
                return new \stdClass();
            case 'server/discover':
                return [
                    'resultType' => 'complete',
                    'supportedVersions' => Versions::SUPPORTED,
                    'capabilities' => ['tools' => new \stdClass()],
                    'instructions' => $this->instructions,
                    'ttlMs' => self::DISCOVER_TTL_MS,
                    'cacheScope' => 'public',
                ];
            case 'tools/list':
                $result = ['tools' => $tools->listTools()];
                if ($modern) {
                    // The list depends on the caller's role and scopes, hence private.
                    $result['ttlMs'] = self::TOOLS_LIST_TTL_MS;
                    $result['cacheScope'] = 'private';
                }
                return $result;
            default: // tools/call
                if (!isset($params['name']) || !is_string($params['name'])) {
                    throw new RpcError(RpcError::INVALID_PARAMS, 'params.name must be a string');
                }
                $arguments = array_key_exists('arguments', $params) ? $params['arguments'] : [];
                // {} and [] both decode to an empty array, so an empty one passes as {}.
                if (!is_array($arguments) || Json::isList($arguments)) {
                    throw new RpcError(RpcError::INVALID_PARAMS, 'params.arguments must be an object');
                }
                return $tools->callTool($params['name'], $arguments);
        }
    }

    /**
     * Every modern result says it is complete and names the server.
     *
     * @param array|\stdClass $result
     */
    private function decorate($result): array
    {
        $result = (array)$result;
        $result['resultType'] = 'complete';
        $meta = isset($result['_meta']) && is_array($result['_meta']) ? $result['_meta'] : [];
        $meta[Versions::META_SERVER_INFO] = $this->serverInfo();
        $result['_meta'] = $meta;
        return $result;
    }

    private function serverInfo(): array
    {
        return ['name' => $this->serverName, 'version' => $this->serverVersion];
    }

    /** @param string|int|null $id */
    private static function failure(int $status, $id, RpcError $e): HttpOutcome
    {
        return new HttpOutcome($status, JsonRpc::error($id, $e));
    }

    /** @param array<string,string> $headers lower-cased names */
    private static function header(array $headers, string $name): ?string
    {
        return isset($headers[$name]) ? trim((string)$headers[$name]) : null;
    }

    /**
     * An Mcp-Name value, with the =?base64?...?= form (used for names a header
     * cannot carry verbatim) decoded. Null when absent or not valid base64.
     */
    private static function decodeSentinel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^=\?base64\?(.*)\?=$/is', $value, $m)) {
            $decoded = base64_decode($m[1], true);
            return $decoded === false ? null : $decoded;
        }
        return $value;
    }
}
