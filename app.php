<?php

/*
    The HTTP pipeline, and nothing else. Slim runs the middleware added last
    first: ErrorMiddleware (every throwable to JSON), then routing (404, 405).
    Slim's own ErrorMiddleware is not added: it renders HTML.

    Routes are relative to the base path, the module's directory as the browser
    sees it ('/modules/mcp'). "/[index.php]" matches both the directory and the
    script. The OAuth routes (/.well-known/*, /oauth/*, spec §5.1) exist only
    while OAuth is served (Config::oauthEnabled()), and /oauth/register only
    while allow_dcr is on; otherwise they are 404 like any unknown path.
    Their actions are built when their route is hit, so the MCP endpoint never
    opens the OAuth store.
*/

use DI\Container;
use FA\Mcp\Config;
use FA\Mcp\Http\ErrorMiddleware;
use FA\Mcp\Http\McpAction;
use FA\Mcp\OAuth\Http\AuthorizeAction;
use FA\Mcp\OAuth\Http\MetadataAction;
use FA\Mcp\OAuth\Http\RegisterAction;
use FA\Mcp\OAuth\Http\TokenAction;
use Slim\App;
use Slim\Factory\AppFactory;

return function (Container $container, string $basePath = ''): App {
    AppFactory::setContainer($container);
    $app = AppFactory::create();
    $app->setBasePath($basePath);

    // POST is the endpoint; McpAction answers GET and DELETE with 405 (no SSE
    // stream, no sessions) and OPTIONS with the CORS preflight.
    $app->map(['POST', 'GET', 'DELETE', 'OPTIONS'], '/[index.php]', McpAction::class);

    /** @var Config $config */
    $config = $container->get(Config::class);
    if ($config->oauthEnabled()) {
        $app->get('/.well-known/oauth-protected-resource', MetadataAction::class . ':protectedResource');
        $app->get('/.well-known/oauth-authorization-server', MetadataAction::class . ':authorizationServer');
        $app->get('/.well-known/openid-configuration', MetadataAction::class . ':authorizationServer');
        $app->map(['GET', 'POST'], '/oauth/authorize', AuthorizeAction::class);
        $app->map(['POST', 'OPTIONS'], '/oauth/token', TokenAction::class);
        if ($config->allowDcr) {
            $app->map(['POST', 'OPTIONS'], '/oauth/register', RegisterAction::class);
        }
    }

    $app->addRoutingMiddleware();
    $app->add(ErrorMiddleware::class);

    return $app;
};
