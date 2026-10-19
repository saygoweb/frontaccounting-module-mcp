<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\OAuth\CurlHttpFetcher;
use PHPUnit\Framework\TestCase;

/**
 * The failure paths of the real fetcher, which need no network: a URL without
 * a host, and a connection pinned to loopback, where nothing serves a valid
 * certificate for the name, so the transfer fails (refused or TLS) without
 * ever leaving the machine.
 */
class CurlHttpFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('Needs ext-curl.');
        }
    }

    public function testAUrlWithoutAHostIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The URL has no host.');
        (new CurlHttpFetcher())->get('https:///client.json', '127.0.0.1', 2, 1024);
    }

    public function testAFailedTransferIsAFetchFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Fetch failed: /');
        (new CurlHttpFetcher())->get('https://mcp-test.invalid/client.json', '127.0.0.1', 2, 1024);
    }

    public function testAnIpv6PinIsBracketed(): void
    {
        // ::1 may not be configured; either way the transfer fails, after the
        // pin was given to curl as [::1].
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Fetch failed: /');
        (new CurlHttpFetcher())->get('https://mcp-test.invalid/client.json', '::1', 2, 1024);
    }
}
