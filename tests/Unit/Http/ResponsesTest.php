<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Http\Responses;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class ResponsesTest extends TestCase
{
    public function testJson(): void
    {
        $response = Responses::json(new Response(), 201, ['a' => 'é', 'b' => []], ['X-One' => '1']);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('1', $response->getHeaderLine('X-One'));
        $this->assertSame(['a' => 'é', 'b' => []], json_decode((string) $response->getBody(), true));
    }

    public function testEmpty(): void
    {
        $response = Responses::empty(new Response(), 202, ['Allow' => 'POST']);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
        $this->assertSame('', (string) $response->getBody());
        $this->assertFalse($response->hasHeader('Content-Type'));
    }

    public function testText(): void
    {
        $response = Responses::text(new Response(), 503, 'unavailable', ['Retry-After' => '60']);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('60', $response->getHeaderLine('Retry-After'));
        $this->assertSame('unavailable', (string) $response->getBody());
    }

    public function testHtml(): void
    {
        $response = Responses::html(new Response(), 403, '<p>No.</p>', ['X-Frame-Options' => 'DENY']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('<p>No.</p>', (string) $response->getBody());
    }

    public function testCallerHeadersCannotOverrideTheContentType(): void
    {
        $response = Responses::text(new Response(), 200, 'x', ['Content-Type' => 'application/json']);
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }
}
