<?php

namespace FA\Mcp\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\OAuth\Metadata;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /.well-known/oauth-protected-resource (RFC 9728) and
 * /.well-known/oauth-authorization-server, /.well-known/openid-configuration
 * (RFC 8414), spec §5.3. Built from base_url, never from the request's Host.
 * Public documents, so open to any origin.
 */
final class MetadataAction extends JsonEndpoint
{
    private Metadata $metadata;

    public function __construct(Config $config, Metadata $metadata, ?RequestContext $context = null)
    {
        parent::__construct($config, $context);
        $this->metadata = $metadata;
    }

    public function protectedResource(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->document($request, $response, $this->metadata->protectedResource());
    }

    public function authorizationServer(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->document($request, $response, $this->metadata->authorizationServer());
    }

    /** @param array<string, mixed> $body */
    private function document(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $body
    ): ResponseInterface {
        return $this->respond(
            $request,
            $response,
            function (ServerRequestInterface $req, ResponseInterface $res, RequestContext $ctx) use ($body) {
                return $this->json($res, 200, $body, ['Cache-Control' => 'max-age=3600']);
            }
        );
    }

    protected function corsHeaders(?string $origin): array
    {
        return ['Access-Control-Allow-Origin' => '*'];
    }

    protected function allowedMethods(): string
    {
        return 'GET';
    }
}
