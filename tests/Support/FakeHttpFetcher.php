<?php

namespace FA\Mcp\Tests\Support;

use FA\Mcp\OAuth\HttpFetcher;

/**
 * Canned responses per URL, and a record of every call. A URL with no canned
 * response fails as a transport error would.
 */
final class FakeHttpFetcher implements HttpFetcher
{
    /** @var array<string, array{status: int, body: string}|\RuntimeException> url => response */
    public array $responses = [];

    /** @var array[] each ['url' => string, 'pinnedIp' => string, 'timeout' => int, 'maxBytes' => int] */
    public array $calls = [];

    public function respond(string $url, int $status, string $body): void
    {
        $this->responses[$url] = ['status' => $status, 'body' => $body];
    }

    public function get(string $url, string $pinnedIp, int $timeoutSeconds, int $maxBytes): array
    {
        $this->calls[] = [
            'url' => $url, 'pinnedIp' => $pinnedIp, 'timeout' => $timeoutSeconds, 'maxBytes' => $maxBytes,
        ];
        if (!isset($this->responses[$url])) {
            throw new \RuntimeException('No canned response for ' . $url);
        }
        $response = $this->responses[$url];
        if ($response instanceof \RuntimeException) {
            throw $response;
        }
        return $response;
    }
}
