<?php

namespace FA\Mcp\Http;

use Psr\Http\Message\ResponseInterface;

/** Response builders for the module's actions. */
final class Responses
{
    /**
     * @param mixed $body encoded as JSON
     * @param array<string, string> $headers
     */
    public static function json(ResponseInterface $res, int $status, $body, array $headers = []): ResponseInterface
    {
        $res = self::withHeaders($res->withStatus($status), $headers)
            ->withHeader('Content-Type', 'application/json');
        $res->getBody()->write(Json::encode($body));
        return $res;
    }

    /** @param array<string, string> $headers */
    public static function empty(ResponseInterface $res, int $status, array $headers = []): ResponseInterface
    {
        return self::withHeaders($res->withStatus($status), $headers);
    }

    /** @param array<string, string> $headers */
    public static function text(ResponseInterface $res, int $status, string $text, array $headers = []): ResponseInterface
    {
        $res = self::withHeaders($res->withStatus($status), $headers)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        $res->getBody()->write($text);
        return $res;
    }

    /** @param array<string, string> $headers */
    public static function html(ResponseInterface $res, int $status, string $html, array $headers = []): ResponseInterface
    {
        $res = self::withHeaders($res->withStatus($status), $headers)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
        $res->getBody()->write($html);
        return $res;
    }

    /** @param array<string, string> $headers */
    private static function withHeaders(ResponseInterface $res, array $headers): ResponseInterface
    {
        foreach ($headers as $name => $value) {
            $res = $res->withHeader($name, $value);
        }
        return $res;
    }
}
