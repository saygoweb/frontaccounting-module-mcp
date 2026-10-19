<?php

namespace FA\Mcp\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;

/**
 * PSR-7 requests for the OAuth action tests: HTTPS from 198.51.100.7 unless
 * the server parameters given say otherwise.
 */
final class Requests
{
    public const SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTPS' => 'on'];

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    public static function get(
        string $path,
        array $query = [],
        array $server = [],
        array $cookies = []
    ): ServerRequestInterface {
        return self::make('GET', $path, $query, $server)->withCookieParams($cookies);
    }

    /**
     * A form POST; $query, when given, stays on the URL.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    public static function form(
        string $path,
        array $post,
        array $query = [],
        array $server = [],
        array $cookies = []
    ): ServerRequestInterface {
        return self::make('POST', $path, $query, $server)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($post)
            ->withCookieParams($cookies);
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    public static function json(
        string $path,
        string $body,
        array $server = [],
        array $headers = []
    ): ServerRequestInterface {
        $request = self::make('POST', $path, [], $server)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($body));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public static function options(string $path, string $origin = 'https://claude.ai'): ServerRequestInterface
    {
        return self::make('OPTIONS', $path, [], [])
            ->withHeader('Origin', $origin)
            ->withHeader('Access-Control-Request-Method', 'POST');
    }

    public static function response(): ResponseInterface
    {
        return new Response();
    }

    /** @return array<string, mixed> the decoded JSON body of a response */
    public static function body(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> the query parameters of a redirect's Location */
    public static function locationQuery(ResponseInterface $response): array
    {
        $query = (string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY);
        parse_str($query, $params);
        return $params;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $server
     */
    private static function make(string $method, string $path, array $query, array $server): ServerRequestInterface
    {
        $uri = $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, $server + self::SERVER);
        return $request->withQueryParams($query);
    }
}
