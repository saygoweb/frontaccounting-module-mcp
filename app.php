<?php

/*
    The HTTP pipeline, and nothing else. Slim runs the middleware added last
    first: ErrorMiddleware (every throwable to JSON), then routing (404, 405).
    Slim's own ErrorMiddleware is not added: it renders HTML.

    Routes are relative to the base path, the module's directory as the browser
    sees it ('/modules/mcp'). "/[index.php]" matches both the directory and the
    script. The OAuth routes (/.well-known/*, /oauth/*) are added below the MCP
    endpoint's.
*/

use DI\Container;
use FA\Mcp\Http\ErrorMiddleware;
use FA\Mcp\Http\McpAction;
use Slim\App;
use Slim\Factory\AppFactory;

return function (Container $container, string $basePath = ''): App {
    AppFactory::setContainer($container);
    $app = AppFactory::create();
    $app->setBasePath($basePath);

    // POST is the endpoint; McpAction answers GET and DELETE with 405 (no SSE
    // stream, no sessions) and OPTIONS with the CORS preflight.
    $app->map(['POST', 'GET', 'DELETE', 'OPTIONS'], '/[index.php]', McpAction::class);

    $app->addRoutingMiddleware();
    $app->add(ErrorMiddleware::class);

    return $app;
};
