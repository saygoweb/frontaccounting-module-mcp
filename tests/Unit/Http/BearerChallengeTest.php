<?php

namespace FA\Mcp\Tests\Unit\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\BearerChallenge;
use PHPUnit\Framework\TestCase;

class BearerChallengeTest extends TestCase
{
    private const BASE = 'https://fa.example.com/modules/mcp';

    public function testNoTokenPointsAtTheResourceMetadata(): void
    {
        $this->assertSame(
            'Bearer resource_metadata="https://fa.example.com/modules/mcp/.well-known/oauth-protected-resource"',
            BearerChallenge::header(Config::fromArray(['base_url' => self::BASE]))
        );
    }

    public function testInvalidToken(): void
    {
        $this->assertSame(
            'Bearer error="invalid_token", '
            . 'resource_metadata="https://fa.example.com/modules/mcp/.well-known/oauth-protected-resource"',
            BearerChallenge::header(Config::fromArray(['base_url' => self::BASE]), BearerChallenge::INVALID_TOKEN)
        );
    }

    public function testWithoutBaseUrlARealm(): void
    {
        $this->assertSame('Bearer realm="frontaccounting-mcp"', BearerChallenge::header(Config::fromArray([])));
        $this->assertSame(
            'Bearer realm="frontaccounting-mcp", error="invalid_token"',
            BearerChallenge::header(Config::fromArray([]), BearerChallenge::INVALID_TOKEN)
        );
    }

    public function testOauthOffMeansNoMetadataToPointAt(): void
    {
        // No metadata document is served with oauth off, so none is named.
        $this->assertSame(
            'Bearer realm="frontaccounting-mcp"',
            BearerChallenge::header(Config::fromArray(['base_url' => self::BASE, 'oauth' => false]))
        );
    }
}
