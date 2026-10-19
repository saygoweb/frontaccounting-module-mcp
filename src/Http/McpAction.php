<?php

namespace FA\Mcp\Http;

use FA\Mcp\Config;
use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Gateway\GraphQlGateway;
use FA\Mcp\Protocol\HttpOutcome;
use FA\Mcp\Protocol\HttpStatusException;
use FA\Mcp\Protocol\ProtocolServer;
use FA\Mcp\Tool\SchemaToolFactory;
use FA\Mcp\Tool\ToolCatalog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The MCP endpoint, `/` under the module: Streamable HTTP, stateless and
 * JSON-only (spec §5.5, §6.1, §10).
 *
 * The checks run in the spec's order (TLS, then Origin, then the bearer
 * token's presence) and each answers before anything touches GraphQL. The
 * token is not decoded here: graphql's Executor is the one judge of it, and
 * its refusal comes back from the gateway as an AuthFailure, which the
 * catalog turns into the 401 or 403 built by authFailure().
 *
 * GET and DELETE are 405 (no SSE stream, no sessions); OPTIONS is the CORS
 * preflight. Whatever PHP or FrontAccounting prints while the message is
 * handled is dropped, so the body is always the one JSON object.
 */
final class McpAction
{
    public const ALLOW = 'POST, OPTIONS';
    public const ALLOW_HEADERS = 'Authorization, Content-Type, MCP-Protocol-Version, Mcp-Method, Mcp-Name, '
        . 'Mcp-Session-Id, Last-Event-ID';
    public const EXPOSE_HEADERS = 'WWW-Authenticate';

    private Config $config;
    private ProtocolServer $protocol;
    private SchemaToolFactory $factory;
    /** @var callable(string, string): GraphQlGateway */
    private $gatewayFactory;
    private ?RequestContext $context;
    private OriginGuard $origins;

    /**
     * @param callable $gatewayFactory (string $bearer, string $clientIp): GraphQlGateway
     * @param RequestContext|null $context the request's context, as the container
     *                                     builds it (graphql's trust_proxy applied);
     *                                     null reads the request itself, trusting no proxy
     */
    public function __construct(
        Config $config,
        ProtocolServer $protocol,
        SchemaToolFactory $factory,
        callable $gatewayFactory,
        ?RequestContext $context = null
    ) {
        $this->config = $config;
        $this->protocol = $protocol;
        $this->factory = $factory;
        $this->gatewayFactory = $gatewayFactory;
        $this->context = $context;
        $this->origins = new OriginGuard($config);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return $this->handle($request, $response);
        } catch (\Throwable $e) {
            error_log('mcp: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return Responses::json($response, 500, self::rpcError(
                -32603,
                $this->config->debug ? 'Internal error: ' . $e->getMessage() : 'Internal error'
            ));
        }
    }

    /**
     * The catalog's AuthFailure mapping: 401 with a fresh challenge, so the
     * client refreshes or signs in again, or 403 when the user's role lacks
     * SA_GRAPHQL, which signing in again would not fix.
     *
     * @return callable(AuthFailure): HttpStatusException
     */
    public static function authFailure(Config $config): callable
    {
        return static function (AuthFailure $e) use ($config): HttpStatusException {
            if ($e->reason() === AuthFailure::UNAUTHENTICATED) {
                return new HttpStatusException(
                    401,
                    ['WWW-Authenticate' => BearerChallenge::header($config, BearerChallenge::INVALID_TOKEN)],
                    ['error' => 'invalid_token', 'message' => $e->getMessage()]
                );
            }
            return new HttpStatusException(403, [], ['error' => 'NO_API_ACCESS', 'message' => $e->getMessage()]);
        };
    }

    private function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ctx = $this->context ?? RequestContext::fromRequest($request, false);

        if ($this->config->requireTls && !$ctx->isHttps()) {
            return Responses::json($response, 403, ['error' => 'TLS_REQUIRED', 'message' => 'HTTPS is required.']);
        }

        $origin = $ctx->origin();
        $cors = $this->origins->isCorsOrigin($origin)
            ? [
                'Access-Control-Allow-Origin' => (string) $origin,
                'Vary' => 'Origin',
                'Access-Control-Expose-Headers' => self::EXPOSE_HEADERS,
            ]
            : [];

        $method = strtoupper($request->getMethod());
        if ($method === 'OPTIONS') {
            return Responses::empty($response, 204, $cors === [] ? ['Allow' => self::ALLOW] : $cors + [
                'Access-Control-Allow-Methods' => self::ALLOW,
                'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
                'Access-Control-Max-Age' => '86400',
            ]);
        }
        if ($method !== 'POST') {
            // GET would open an SSE stream and DELETE end a session; this
            // server offers neither (spec §5.1, §6.1).
            return Responses::json(
                $response,
                405,
                self::rpcError(-32600, 'Method not allowed: POST JSON-RPC messages to this URL.'),
                ['Allow' => self::ALLOW] + $cors
            );
        }

        if (!$this->origins->allows($origin)) {
            return Responses::json($response, 403, OriginGuard::FORBIDDEN_BODY);
        }

        $bearer = $ctx->bearer();
        if ($bearer === null) {
            return Responses::json(
                $response,
                401,
                ['error' => 'UNAUTHENTICATED', 'message' => 'A bearer token is required.'],
                ['WWW-Authenticate' => BearerChallenge::header($this->config)] + $cors
            );
        }

        /** @var GraphQlGateway $gql */
        $gql = ($this->gatewayFactory)($bearer, $ctx->clientIp());
        $tools = new ToolCatalog($gql, $this->factory, $this->config->escapeHatch, self::authFailure($this->config));

        // FrontAccounting, booted by the first Executor call, may print notices
        // or HTML; none of it belongs in the JSON body. index.php's capture
        // would drop it too; this one logs it against the message that caused
        // it, and keeps the action safe to call on its own.
        $outcome = OutputCapture::around(function () use ($request, $ctx, $tools): HttpOutcome {
            return $this->protocol->handle((string) $request->getBody(), $ctx->mcpHeaders(), $tools);
        });

        $headers = $outcome->headers() + $cors;
        return $outcome->body() === null
            ? Responses::empty($response, $outcome->status(), $headers)
            : Responses::json($response, $outcome->status(), $outcome->body(), $headers);
    }

    /** @return array{jsonrpc: string, id: null, error: array{code: int, message: string}} */
    private static function rpcError(int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]];
    }
}
