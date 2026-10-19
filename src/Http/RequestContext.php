<?php

namespace FA\Mcp\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What the request says about itself (spec §5.5): HTTPS or not, the end
 * client's address, its Origin, and the headers the protocol layer reads.
 *
 * Forwarded headers are believed only with graphql's trust_proxy, exactly as
 * graphql's own RequestInfo believes them: X-Forwarded-Proto https makes the
 * request HTTPS, and the first X-Forwarded-For hop is the client.
 *
 * Headers are read through PSR-7 first. A request built from a server
 * environment that dropped some of them falls back to the CGI variables:
 * HTTP_MCP_* for the Mcp-* headers, and HTTP_AUTHORIZATION or
 * REDIRECT_HTTP_AUTHORIZATION (what .htaccess's SetEnvIf and a rewrite leave)
 * for Authorization.
 */
final class RequestContext
{
    private bool $https;
    private string $clientIp;
    private ?string $origin;
    /** @var array<string, string> */
    private array $mcpHeaders;

    /** @param array<string, string> $mcpHeaders */
    private function __construct(bool $https, string $clientIp, ?string $origin, array $mcpHeaders)
    {
        $this->https = $https;
        $this->clientIp = $clientIp;
        $this->origin = $origin;
        $this->mcpHeaders = $mcpHeaders;
    }

    /** @param bool $trustProxy graphql's trust_proxy setting */
    public static function fromRequest(ServerRequestInterface $r, bool $trustProxy): self
    {
        $server = $r->getServerParams();
        $https = isset($server['HTTPS']) && $server['HTTPS'] !== ''
            && strtolower((string) $server['HTTPS']) !== 'off';
        $clientIp = (string) ($server['REMOTE_ADDR'] ?? '');

        if ($trustProxy) {
            if (strtolower(trim($r->getHeaderLine('X-Forwarded-Proto'))) === 'https') {
                $https = true;
            }
            $forwarded = $r->getHeaderLine('X-Forwarded-For');
            if (trim($forwarded) !== '') {
                $clientIp = trim(explode(',', $forwarded)[0]);
            }
        }

        $origin = trim($r->getHeaderLine('Origin'));

        return new self($https, $clientIp, $origin === '' ? null : $origin, self::readMcpHeaders($r, $server));
    }

    public function isHttps(): bool
    {
        return $this->https;
    }

    /** The end client's address, as Executor and the OAuth rate limits take it. */
    public function clientIp(): string
    {
        return $this->clientIp;
    }

    /** The Origin header, trimmed; null when absent or empty. */
    public function origin(): ?string
    {
        return $this->origin;
    }

    /**
     * The lower-cased mcp-* headers and authorization, as ProtocolServer::handle() takes them.
     *
     * @return array<string, string>
     */
    public function mcpHeaders(): array
    {
        return $this->mcpHeaders;
    }

    /** The token of an `Authorization: Bearer <token>` header; null for none or any other form. */
    public function bearer(): ?string
    {
        $authorization = trim($this->mcpHeaders['authorization'] ?? '');
        return preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m) === 1 ? $m[1] : null;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function readMcpHeaders(ServerRequestInterface $r, array $server): array
    {
        $headers = [];
        foreach (array_keys($r->getHeaders()) as $name) {
            $lower = strtolower((string) $name);
            if (strpos($lower, 'mcp-') === 0 || $lower === 'authorization') {
                $headers[$lower] = $r->getHeaderLine((string) $name);
            }
        }
        foreach ($server as $key => $value) {
            if (!is_string($value) || strpos((string) $key, 'HTTP_MCP_') !== 0) {
                continue;
            }
            $lower = strtr(strtolower(substr((string) $key, 5)), '_', '-');
            if (!isset($headers[$lower])) {
                $headers[$lower] = $value;
            }
        }
        if (($headers['authorization'] ?? '') === '') {
            unset($headers['authorization']);
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
                if (isset($server[$key]) && is_string($server[$key]) && $server[$key] !== '') {
                    $headers['authorization'] = $server[$key];
                    break;
                }
            }
        }
        return $headers;
    }
}
