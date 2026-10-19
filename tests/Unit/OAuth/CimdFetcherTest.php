<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\OAuth\CimdFetcher;
use FA\Mcp\OAuth\OAuthError;
use FA\Mcp\Tests\Support\FakeHttpFetcher;
use PHPUnit\Framework\TestCase;

class CimdFetcherTest extends TestCase
{
    private const URL = 'https://app.example/oauth/client.json';

    private FakeHttpFetcher $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpFetcher();
    }

    private function fetcher(array $addresses): CimdFetcher
    {
        return new CimdFetcher($this->http, function (string $host) use ($addresses) {
            return $addresses;
        });
    }

    private function document(array $overrides = []): string
    {
        return json_encode(array_merge([
            'client_id' => self::URL,
            'client_name' => 'Example App',
            'redirect_uris' => ['https://app.example/callback'],
        ], $overrides));
    }

    private function assertInvalidClient(CimdFetcher $fetcher, string $url): void
    {
        try {
            $fetcher->fetch($url);
            $this->fail('Expected invalid_client');
        } catch (OAuthError $e) {
            $this->assertSame('invalid_client', $e->error());
        }
    }

    public function nonPublicAddresses(): array
    {
        return [
            'private 10/8' => ['10.1.2.3'],
            'private 172.16/12' => ['172.20.0.1'],
            'private 192.168/16' => ['192.168.1.1'],
            'loopback' => ['127.0.0.1'],
            'link-local' => ['169.254.169.254'],
            'CGNAT' => ['100.64.0.1'],
            'this network' => ['0.0.0.0'],
            'multicast' => ['224.0.0.1'],
            'reserved' => ['240.0.0.1'],
            'IPv6 loopback' => ['::1'],
            'IPv6 unspecified' => ['::'],
            'IPv6 ULA' => ['fd00::1'],
            'IPv6 link-local' => ['fe80::1'],
            'IPv4-mapped' => ['::ffff:10.0.0.1'],
            'IPv4-mapped public' => ['::ffff:93.184.216.34'],
            'garbage' => ['not-an-ip'],
        ];
    }

    /** @dataProvider nonPublicAddresses */
    public function testNonPublicAddressRejectedBeforeFetching(string $address): void
    {
        $this->http->respond(self::URL, 200, $this->document());
        $this->assertInvalidClient($this->fetcher([$address]), self::URL);
        $this->assertSame([], $this->http->calls);
    }

    public function testAnyNonPublicAddressAmongSeveralRejects(): void
    {
        $this->http->respond(self::URL, 200, $this->document());
        $this->assertInvalidClient($this->fetcher(['93.184.216.34', '10.0.0.1']), self::URL);
        $this->assertSame([], $this->http->calls);
    }

    public function testNoAddressRejected(): void
    {
        $this->assertInvalidClient($this->fetcher([]), self::URL);
        $this->assertSame([], $this->http->calls);
    }

    public function testHttpRejected(): void
    {
        $this->assertInvalidClient($this->fetcher(['93.184.216.34']), 'http://app.example/oauth/client.json');
        $this->assertSame([], $this->http->calls);
    }

    public function testOtherPortRejected(): void
    {
        $this->assertInvalidClient($this->fetcher(['93.184.216.34']), 'https://app.example:8443/oauth/client.json');
        $this->assertSame([], $this->http->calls);
    }

    public function testUserinfoRejected(): void
    {
        $this->assertInvalidClient($this->fetcher(['93.184.216.34']), 'https://u:p@app.example/oauth/client.json');
        $this->assertSame([], $this->http->calls);
    }

    public function testClientIdMismatchRejected(): void
    {
        $this->http->respond(self::URL, 200, $this->document(['client_id' => 'https://other.example/c.json']));
        $this->assertInvalidClient($this->fetcher(['93.184.216.34']), self::URL);
    }

    public function testNon200Rejected(): void
    {
        $this->http->respond(self::URL, 404, $this->document());
        $this->assertInvalidClient($this->fetcher(['93.184.216.34']), self::URL);
    }

    public function testBadJsonRejected(): void
    {
        $this->http->respond(self::URL, 200, '{not json');
        $this->assertInvalidClient($this->fetcher(['93.184.216.34']), self::URL);
    }

    public function testUnacceptableRedirectRejected(): void
    {
        $this->http->respond(self::URL, 200, $this->document(['redirect_uris' => ['http://app.example/cb']]));
        $this->assertInvalidClient($this->fetcher(['93.184.216.34']), self::URL);
    }

    public function testTransportErrorRejected(): void
    {
        $this->http->responses[self::URL] = new \RuntimeException('too big');
        $this->assertInvalidClient($this->fetcher(['93.184.216.34']), self::URL);
    }

    public function testHappyPathPinsFirstAddress(): void
    {
        $this->http->respond(self::URL, 200, $this->document());
        $client = $this->fetcher(['93.184.216.34', '2606:2800:220:1::1'])->fetch(self::URL);

        $this->assertSame(self::URL, $client->clientId());
        $this->assertSame('cimd', $client->kind());
        $this->assertSame('Example App', $client->name());
        $this->assertSame(['https://app.example/callback'], $client->redirectUris());
        $this->assertSame([[
            'url' => self::URL, 'pinnedIp' => '93.184.216.34', 'timeout' => 5, 'maxBytes' => 65536
        ]], $this->http->calls);
    }
}
