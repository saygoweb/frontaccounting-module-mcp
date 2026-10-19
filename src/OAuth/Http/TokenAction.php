<?php

namespace FA\Mcp\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\OAuth\AuthorizationServer;
use FA\Mcp\OAuth\OAuthError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /oauth/token: the authorization_code and refresh_token grants (spec
 * §5.3 step 8, §5.4), form-encoded. ClientRegistry prunes idle clients from
 * here now and then, so the sweep runs without DCR too.
 */
final class TokenAction extends JsonEndpoint
{
    private const NO_STORE = ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'];

    private AuthorizationServer $server;

    public function __construct(Config $config, AuthorizationServer $server, ?RequestContext $context = null)
    {
        parent::__construct($config, $context);
        $this->server = $server;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->respond($request, $response, function (
            ServerRequestInterface $req,
            ResponseInterface $res,
            RequestContext $ctx
        ): ResponseInterface {
            $post = $req->getParsedBody();
            try {
                $body = $this->server->token(is_array($post) ? $post : []);
            } catch (OAuthError $e) {
                $headers = self::NO_STORE;
                if ($e->error() === 'invalid_client') {
                    $headers['WWW-Authenticate'] = 'Bearer';
                }
                return $this->oauthError($res, $e, $headers);
            }
            return $this->json($res, 200, $body, self::NO_STORE);
        });
    }

    protected function allowedMethods(): string
    {
        return 'POST, OPTIONS';
    }
}
