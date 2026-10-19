<?php

namespace FA\Mcp\Tests\Http;

/**
 * Talks to the module over HTTP (Apache in the FrontAccounting CI image), and
 * issues tokens with the GraphQL module's bin/fa-token, as an administrator
 * setting up Claude Code would.
 */
trait McpClient
{
    /** The CI image's test user: role FA CI, every area, SA_GRAPHQL included. */
    private static function ciUser(): string
    {
        return (string) (getenv('FA_MCP_TEST_USER') ?: 'test');
    }

    private static function mcpUrl(): string
    {
        $url = getenv('FA_MCP_URL');
        if ($url !== false && $url !== '') {
            return $url;
        }
        return rtrim((string) (getenv('FA_URL') ?: 'http://localhost'), '/') . '/modules/mcp/';
    }

    /**
     * Any request. $path is appended to the module URL ('' for the endpoint).
     *
     * @param array<string, string> $headers name => value
     * @return array{status: int, headers: array<string, string>, body: array|null, raw: string}
     */
    private function http(string $method, string $path = '', array $headers = [], ?string $body = null): array
    {
        $lines = ['User-Agent: fa-mcp-tests'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $received = [];
        $curl = curl_init(self::mcpUrl() . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        $this->assertIsString($raw, 'no response from ' . self::mcpUrl() . $path . ': ' . curl_error($curl));
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $decoded = json_decode($raw, true);

        return [
            'status' => $status,
            'headers' => $received,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => $raw,
        ];
    }

    /**
     * A legacy (2025-06-18) JSON-RPC request.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: array|null, raw: string}
     */
    private function rpc(string $method, array $params = [], ?string $token = null, array $headers = []): array
    {
        return $this->post(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params],
            $token,
            $headers + ['MCP-Protocol-Version' => '2025-06-18']
        );
    }

    /**
     * A 2026-07-28 request: the version in _meta, repeated with the method
     * (and, for tools/call, the tool name) in the Mcp-* headers.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: array|null, raw: string}
     */
    private function modern(string $method, array $params = [], ?string $token = null, array $headers = []): array
    {
        $params['_meta']['io.modelcontextprotocol/protocolVersion'] = '2026-07-28';
        $headers += ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if ($method === 'tools/call' && isset($params['name'])) {
            $headers += ['Mcp-Name' => (string) $params['name']];
        }
        return $this->post(
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params],
            $token,
            $headers
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: array|null, raw: string}
     */
    private function post(array $message, ?string $token, array $headers): array
    {
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $this->http('POST', '', $headers, (string) json_encode($message));
    }

    /**
     * graphql's bin/fa-token, run as this process's user.
     *
     * @param string[] $args
     * @return array{0: int, 1: string, 2: string} exit status, stdout, stderr
     */
    private static function faToken(array $args): array
    {
        $bin = dirname(__DIR__, 3) . '/graphql/bin/fa-token';
        $command = 'php ' . escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('could not run ' . $command);
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    /**
     * A machine token for the CI user, as `fa-token issue` prints it: the token
     * alone on stdout, "Issued machine token <jti> for ..." on stderr.
     *
     * @return array{token: string, jti: string}
     */
    private static function issueToken(bool $readOnly, string $label = 'mcp-http-test'): array
    {
        $args = ['issue', '--company', '0', '--user', self::ciUser(), '--days', '1', '--label', $label];
        if ($readOnly) {
            $args[] = '--read-only';
        }
        [$status, $out, $err] = self::faToken($args);
        if ($status !== 0 || !preg_match('/Issued machine token (\S+) for/', $err, $m)) {
            throw new \RuntimeException("fa-token issue failed ($status): $err");
        }
        return ['token' => trim($out), 'jti' => $m[1]];
    }

    private static function revokeToken(string $jti): bool
    {
        return self::faToken(['revoke', '--company', '0', $jti])[0] === 0;
    }
}
