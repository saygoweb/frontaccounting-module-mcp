<?php

namespace FA\Mcp\Tests\Unit\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\OAuth\Http\MetadataAction;
use FA\Mcp\OAuth\Metadata;
use FA\Mcp\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

class MetadataActionTest extends TestCase
{
    private const BASE = 'https://fa.example.com/modules/mcp';

    /** @param array<string, mixed> $config */
    private static function action(array $config = []): MetadataAction
    {
        $config = Config::fromArray($config + ['base_url' => self::BASE]);
        return new MetadataAction($config, new Metadata($config->baseUrl, $config->allowDcr, $config->allowCimd));
    }

    public function testProtectedResource(): void
    {
        $res = self::action()->protectedResource(
            Requests::get('/modules/mcp/.well-known/oauth-protected-resource'),
            Requests::response()
        );
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $this->assertSame('*', $res->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('max-age=3600', $res->getHeaderLine('Cache-Control'));
        $body = Requests::body($res);
        $this->assertSame(self::BASE . '/', $body['resource']);
        $this->assertSame([self::BASE], $body['authorization_servers']);
        $this->assertSame(['read', 'write'], $body['scopes_supported']);
    }

    public function testAuthorizationServer(): void
    {
        $res = self::action()->authorizationServer(
            Requests::get('/modules/mcp/.well-known/oauth-authorization-server'),
            Requests::response()
        );
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('*', $res->getHeaderLine('Access-Control-Allow-Origin'));
        $body = Requests::body($res);
        $this->assertSame(self::BASE, $body['issuer']);
        $this->assertSame(self::BASE . '/oauth/authorize', $body['authorization_endpoint']);
        $this->assertSame(self::BASE . '/oauth/token', $body['token_endpoint']);
        $this->assertSame(self::BASE . '/oauth/register', $body['registration_endpoint']);
        $this->assertSame(['S256'], $body['code_challenge_methods_supported']);
        $this->assertTrue($body['authorization_response_iss_parameter_supported']);
    }

    public function testNoRegistrationEndpointWithoutDcr(): void
    {
        $body = Requests::body(self::action(['allow_dcr' => false])->authorizationServer(
            Requests::get('/modules/mcp/.well-known/oauth-authorization-server'),
            Requests::response()
        ));
        $this->assertArrayNotHasKey('registration_endpoint', $body);
    }

    public function testPlainHttpIsRefusedWhenTlsIsRequired(): void
    {
        $res = self::action()->protectedResource(
            Requests::get('/modules/mcp/.well-known/oauth-protected-resource', [], ['HTTPS' => '']),
            Requests::response()
        );
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(
            ['error' => 'invalid_request', 'error_description' => 'HTTPS is required'],
            Requests::body($res)
        );
    }

    public function testPlainHttpIsServedWhenTlsIsNotRequired(): void
    {
        $res = self::action(['require_tls' => false])->authorizationServer(
            Requests::get('/modules/mcp/.well-known/oauth-authorization-server', [], ['HTTPS' => '']),
            Requests::response()
        );
        $this->assertSame(200, $res->getStatusCode());
    }
}
