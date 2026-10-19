<?php

namespace FA\Mcp\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\Json;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\OAuth\ClientRegistry;
use FA\Mcp\OAuth\Clock;
use FA\Mcp\OAuth\OAuthError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /oauth/register: RFC 7591 dynamic client registration, public clients
 * only, rate-limited per source IP (spec §5.3 step 4, §7). Routed only while
 * allow_dcr is on.
 */
final class RegisterAction extends JsonEndpoint
{
    private ClientRegistry $clients;
    private Clock $clock;

    public function __construct(
        Config $config,
        ClientRegistry $clients,
        Clock $clock,
        ?RequestContext $context = null
    ) {
        parent::__construct($config, $context);
        $this->clients = $clients;
        $this->clock = $clock;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->respond($request, $response, function (
            ServerRequestInterface $req,
            ResponseInterface $res,
            RequestContext $ctx
        ): ResponseInterface {
            try {
                $metadata = Json::decodeObject((string) $req->getBody());
                if ($metadata === null) {
                    throw new OAuthError('invalid_client_metadata', 'The request body must be a JSON object');
                }
                $issuedAt = $this->clock->now();
                $client = $this->clients->register($metadata, $ctx->clientIp());
            } catch (OAuthError $e) {
                return $this->oauthError($res, $e);
            }

            return $this->json($res, 201, [
                'client_id' => $client->clientId(),
                'client_name' => $client->name(),
                'redirect_uris' => $client->redirectUris(),
                'token_endpoint_auth_method' => 'none',
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'client_id_issued_at' => $issuedAt,
            ], ['Cache-Control' => 'no-store']);
        });
    }

    protected function allowedMethods(): string
    {
        return 'POST, OPTIONS';
    }
}
