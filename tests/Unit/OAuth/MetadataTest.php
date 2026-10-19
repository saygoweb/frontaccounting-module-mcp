<?php

namespace FA\Mcp\Tests\Unit\OAuth;

use FA\Mcp\OAuth\Metadata;
use PHPUnit\Framework\TestCase;

class MetadataTest extends TestCase
{
    private const BASE = 'https://fa.example.com/modules/mcp';

    public function testProtectedResource(): void
    {
        $this->assertSame([
            'resource' => 'https://fa.example.com/modules/mcp/',
            'authorization_servers' => ['https://fa.example.com/modules/mcp'],
            'scopes_supported' => ['read', 'write'],
            'bearer_methods_supported' => ['header'],
        ], (new Metadata(self::BASE, true, true))->protectedResource());
    }

    public function testAuthorizationServer(): void
    {
        $this->assertSame([
            'issuer' => 'https://fa.example.com/modules/mcp',
            'authorization_endpoint' => 'https://fa.example.com/modules/mcp/oauth/authorize',
            'token_endpoint' => 'https://fa.example.com/modules/mcp/oauth/token',
            'registration_endpoint' => 'https://fa.example.com/modules/mcp/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => ['read', 'write'],
        ], (new Metadata(self::BASE, true, true))->authorizationServer());
    }

    public function testNoRegistrationEndpointWhenDcrOff(): void
    {
        $meta = (new Metadata(self::BASE, false, false))->authorizationServer();
        $this->assertArrayNotHasKey('registration_endpoint', $meta);
        $this->assertFalse($meta['client_id_metadata_document_supported']);
    }

    public function testResourceAndResourceMetadataUrl(): void
    {
        $metadata = new Metadata(self::BASE, true, true);
        $this->assertSame('https://fa.example.com/modules/mcp/', $metadata->resource());
        $this->assertSame(
            'https://fa.example.com/modules/mcp/.well-known/oauth-protected-resource',
            $metadata->resourceMetadataUrl()
        );
    }

    public function testIssuer(): void
    {
        $this->assertSame(self::BASE, (new Metadata(self::BASE, true, true))->issuer());
    }
}
