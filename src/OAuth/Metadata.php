<?php

namespace FA\Mcp\OAuth;

/**
 * The two discovery documents (RFC 9728 and RFC 8414), spec §5.3, and the
 * URLs they are built from. The issuer is base_url, never derived from the
 * request's Host.
 */
final class Metadata
{
    public const SCOPES = ['read', 'write'];

    private string $baseUrl;
    private bool $allowDcr;
    private bool $allowCimd;

    /** @param string $baseUrl config base_url: absolute, no trailing slash */
    public function __construct(string $baseUrl, bool $allowDcr, bool $allowCimd)
    {
        $this->baseUrl = $baseUrl;
        $this->allowDcr = $allowDcr;
        $this->allowCimd = $allowCimd;
    }

    /** The authorization server's issuer: base_url. */
    public function issuer(): string
    {
        return $this->baseUrl;
    }

    /** The MCP endpoint, the protected resource: base_url + '/'. */
    public function resource(): string
    {
        return $this->baseUrl . '/';
    }

    /** Where the 401's WWW-Authenticate resource_metadata points. */
    public function resourceMetadataUrl(): string
    {
        return $this->baseUrl . '/.well-known/oauth-protected-resource';
    }

    /** @return array<string, mixed> RFC 9728 protected-resource metadata */
    public function protectedResource(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->baseUrl],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
        ];
    }

    /** @return array<string, mixed> RFC 8414 authorization-server metadata */
    public function authorizationServer(): array
    {
        $meta = [
            'issuer' => $this->baseUrl,
            'authorization_endpoint' => $this->baseUrl . '/oauth/authorize',
            'token_endpoint' => $this->baseUrl . '/oauth/token',
        ];
        if ($this->allowDcr) {
            $meta['registration_endpoint'] = $this->baseUrl . '/oauth/register';
        }
        return $meta + [
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => $this->allowCimd,
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => self::SCOPES,
        ];
    }
}
