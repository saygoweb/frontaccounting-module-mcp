<?php

namespace FA\Mcp\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\McpAction;
use FA\Mcp\Http\OriginGuard;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\Http\Responses;
use FA\Mcp\OAuth\OAuthError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What the OAuth endpoints a client calls directly (token, registration,
 * metadata) do around their own work: answer a CORS preflight, refuse plain
 * HTTP when TLS is required, add CORS headers for an allowed origin, and turn
 * anything thrown into a logged, generic 500 server_error (spec §7, Failure).
 *
 * None of them reads a cookie. CORS is offered to the origins the MCP
 * endpoint accepts (OriginGuard); a foreign origin is not refused, it just
 * gets no Access-Control-Allow-Origin.
 */
abstract class JsonEndpoint
{
    protected Config $config;
    private bool $trustProxy;
    private OriginGuard $origins;

    /** @param bool $trustProxy graphql's trust_proxy: believe X-Forwarded-Proto and -For */
    public function __construct(Config $config, bool $trustProxy = false)
    {
        $this->config = $config;
        $this->trustProxy = $trustProxy;
        $this->origins = new OriginGuard($config);
    }

    /** @return string e.g. 'POST, OPTIONS' */
    abstract protected function allowedMethods(): string;

    /**
     * @param callable(ServerRequestInterface, ResponseInterface, RequestContext): ResponseInterface $handle
     */
    protected function respond(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $handle
    ): ResponseInterface {
        try {
            $ctx = RequestContext::fromRequest($request, $this->trustProxy);
            $cors = $this->corsHeaders($ctx->origin());
            if (strtoupper($request->getMethod()) === 'OPTIONS') {
                return Responses::empty($response, 204, $cors === [] ? ['Allow' => $this->allowedMethods()] : $cors + [
                    'Access-Control-Allow-Methods' => $this->allowedMethods(),
                    'Access-Control-Allow-Headers' => McpAction::ALLOW_HEADERS,
                    'Access-Control-Max-Age' => '86400',
                ]);
            }
            if ($this->config->requireTls && !$ctx->isHttps()) {
                return $this->json($response, 400, [
                    'error' => 'invalid_request',
                    'error_description' => 'HTTPS is required',
                ], $cors);
            }
            $result = $handle($request, $response, $ctx);
            foreach ($cors as $name => $value) {
                $result = $result->withHeader($name, $value);
            }
            return $result;
        } catch (\Throwable $e) {
            self::log($e);
            $body = ['error' => 'server_error'];
            if ($this->config->debug) {
                $body['error_description'] = get_class($e) . ': ' . $e->getMessage();
            }
            return $this->json($response, 500, $body);
        }
    }

    /** @return array<string, string> the CORS headers for $origin; none for a foreign one */
    protected function corsHeaders(?string $origin): array
    {
        if (!$this->origins->isCorsOrigin($origin)) {
            return [];
        }
        return [
            'Access-Control-Allow-Origin' => (string) $origin,
            'Vary' => 'Origin',
            'Access-Control-Expose-Headers' => McpAction::EXPOSE_HEADERS,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function json(ResponseInterface $res, int $status, array $body, array $headers = []): ResponseInterface
    {
        return Responses::json($res, $status, $body, $headers);
    }

    /** @param array<string, string> $headers */
    protected function oauthError(ResponseInterface $res, OAuthError $e, array $headers = []): ResponseInterface
    {
        if ($e->httpStatus() >= 500) {
            self::log($e);
        }
        return $this->json($res, $e->httpStatus(), $e->toArray(), $headers);
    }

    /**
     * Into PHP's error log (FrontAccounting's, once it has booted). An
     * OAuthError is logged as the failure behind it. Exception messages here
     * come from this module and graphql, which never put a token, code or
     * password in one.
     */
    public static function log(\Throwable $e): void
    {
        if ($e instanceof OAuthError && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }
        error_log('mcp: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
}
