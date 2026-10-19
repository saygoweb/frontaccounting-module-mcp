<?php

namespace FA\Mcp\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Psr7\Response;

/**
 * The outermost middleware: whatever is thrown past an action, the client gets
 * JSON (spec §7 "Failure"), never Slim's HTML error page. Routing's own
 * refusals (404 for a path the module does not serve, 405 for a method)
 * keep their status; anything else is a JSON-RPC -32603.
 */
final class ErrorMiddleware implements MiddlewareInterface
{
    private bool $debug;

    public function __construct(bool $debug)
    {
        $this->debug = $debug;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            $headers = [];
            if ($e instanceof HttpMethodNotAllowedException) {
                $headers['Allow'] = implode(', ', $e->getAllowedMethods());
            }
            $body = self::error(-32600, $e->getMessage());
            return Responses::json(new Response(), (int) $e->getCode(), $body, $headers);
        } catch (\Throwable $e) {
            error_log('mcp: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $message = $this->debug ? 'Internal error: ' . $e->getMessage() : 'Internal error';
            return Responses::json(new Response(), 500, self::error(-32603, $message));
        }
    }

    /** @return array{jsonrpc: string, id: null, error: array{code: int, message: string}} */
    private static function error(int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]];
    }
}
