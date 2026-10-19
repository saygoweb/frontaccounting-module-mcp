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

    /** One address inside each blocked range, IPv4 ones also as IPv4-mapped and IPv4-compatible IPv6. */
    public function blockedRangeAddresses(): array
    {
        $v4 = [
            '0.0.0.0/8' => '0.1.2.3', '10.0.0.0/8' => '10.9.9.9', '100.64.0.0/10' => '100.127.255.254',
            '127.0.0.0/8' => '127.1.1.1', '169.254.0.0/16' => '169.254.1.1', '172.16.0.0/12' => '172.31.0.1',
            '192.0.0.0/24' => '192.0.0.8', '192.0.2.0/24' => '192.0.2.10', '192.168.0.0/16' => '192.168.255.1',
            '198.18.0.0/15' => '198.19.255.1', '198.51.100.0/24' => '198.51.100.7',
            '203.0.113.0/24' => '203.0.113.9', '224.0.0.0/4' => '239.1.1.1', '240.0.0.0/4' => '250.1.2.3',
            '255.255.255.255' => '255.255.255.255',
        ];
        $cases = [];
        foreach ($v4 as $range => $address) {
            $cases[$range] = [$address];
            $cases[$range . ' IPv4-mapped'] = ['::ffff:' . $address];
            $cases[$range . ' IPv4-compatible'] = ['::' . $address];
        }
        return $cases + [
            '::/96 IPv4-compatible public' => ['::93.184.216.34'],
            '100::/64 discard-only' => ['100::1'],
            '2001:db8::/32 documentation' => ['2001:db8:1234::1'],
            '64:ff9b::/96 NAT64' => ['64:ff9b::a00:1'],
            '2002::/16 6to4' => ['2002:a00:1::1'],
            'fc00::/7 ULA' => ['fc00::1'],
            'ff00::/8 multicast' => ['ff02::1'],
        ];
    }

    /** @dataProvider blockedRangeAddresses */
    public function testBlockedRangeIsNotPublic(string $address): void
    {
        $this->assertFalse(CimdFetcher::isPublic($address));
    }

    /** Just outside the blocked ranges. */
    public function publicAddresses(): array
    {
        return [
            ['93.184.216.34'], ['192.0.1.1'], ['192.0.3.1'], ['198.17.255.255'], ['198.20.0.1'],
            ['198.51.101.1'], ['203.0.114.1'], ['223.255.255.255'], ['100.128.0.1'],
            ['2606:2800:220:1:248:1893:25c8:1946'], ['100:0:0:1::1'], ['2001:db9::1'], ['2001:4860::8888'],
        ];
    }

    /** @dataProvider publicAddresses */
    public function testAddressOutsideTheBlockedRangesIsPublic(string $address): void
    {
        $this->assertTrue(CimdFetcher::isPublic($address));
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

    /** @return array<string, array{string, string|null}> */
    public function hostOfCases(): array
    {
        return [
            'plain' => ['https://App.Example/client.json', 'app.example'],
            'explicit 443' => ['https://app.example:443/c', 'app.example'],
            'IPv6 literal' => ['https://[2606:2800:220:1::1]/c', '2606:2800:220:1::1'],
            'http' => ['http://app.example/c', null],
            'other port' => ['https://app.example:8443/c', null],
            'userinfo' => ['https://u:p@app.example/c', null],
            'user only' => ['https://u@app.example/c', null],
            'no path' => ['https://app.example', null],
            'root path' => ['https://app.example/', null],
            'fragment' => ['https://app.example/c#x', null],
            'space' => ['https://app.example/a b', null],
            'control character' => ["https://app.example/a\x01", null],
            'not a URL' => ['client', null],
        ];
    }

    /** @dataProvider hostOfCases */
    public function testHostOf(string $url, ?string $host): void
    {
        $this->assertSame($host, CimdFetcher::hostOf($url));
    }

    public function testDnsAddressesResolvesLocalhostWithoutTheNetwork(): void
    {
        $this->assertContains('127.0.0.1', CimdFetcher::dnsAddresses('localhost'));
    }

    public function testTheDefaultResolverIsDnsAndLoopbackIsRefusedBeforeFetching(): void
    {
        $fetcher = new CimdFetcher($this->http);
        try {
            $fetcher->fetch('https://localhost/client.json');
            $this->fail('Expected invalid_client');
        } catch (OAuthError $e) {
            $this->assertSame('invalid_client', $e->error());
        }
        $this->assertSame([], $this->http->calls);
    }
}
