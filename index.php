<?php

/*
    The MCP endpoint, and the module's every other URL: .htaccess sends each path
    under modules/mcp/ here.

    The GraphQL module's autoloader is required before this module's own (spec
    §3.2), so one copy of webonyx, Slim and PHP-DI is loaded and graphql's wins.
    Without graphql, or with an Executor of another contract, every URL answers
    503 text/plain. OutputCapture then starts before anything else runs: nothing
    PHP or FrontAccounting prints, and no header they set, reaches the client.
    Once the app runs, ErrorMiddleware answers every failure as JSON; a failure
    before that is answered here with a JSON-RPC -32603. Every response goes out
    through $send, which ends the capture immediately before emitting.
*/

use FA\GraphQL\Config as GraphQlConfig;
use FA\Mcp\Config;
use FA\Mcp\Http\OutputCapture;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\Http\RootRewrite;
use FA\Mcp\Runtime\GraphQlRequirement;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\ResponseEmitter;

$mcpUnavailable = static function (string $message): void {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message, "\n";
};

$mcpGraphQlAutoload = __DIR__ . '/../graphql/vendor/autoload.php';
if (!is_file($mcpGraphQlAutoload)) {
    // This module's autoloader is not loaded yet: GraphQlRequirement's message, verbatim.
    $mcpUnavailable('FrontAccounting MCP needs the GraphQL module (Executor contract 1.x), active.');
    return;
}
require $mcpGraphQlAutoload;
require __DIR__ . '/vendor/autoload.php';

$problem = (new GraphQlRequirement())->check();
if ($problem !== null) {
    $mcpUnavailable($problem);
    return;
}

OutputCapture::start();

$send = static function (ResponseInterface $response): void {
    OutputCapture::end();
    (new ResponseEmitter())->emit($response);
    OutputCapture::afterEmit();
};

$fail = static function (): ResponseInterface {
    $response = new Response(500);
    $response->getBody()->write(OutputCapture::BODY);

    return $response->withHeader('Content-Type', 'application/json');
};

try {
    $request = ServerRequestFactory::createFromGlobals();
    $config = Config::fromFile(__DIR__ . '/config_mcp.php');
    $graphqlConfig = GraphQlConfig::fromFile(__DIR__ . '/../graphql/config_graphql.php');
    $context = RequestContext::fromRequest($request, $graphqlConfig->trustProxy);
    $container = (require __DIR__ . '/container.php')($config, $graphqlConfig, $context);
    // The module's directory, as the browser sees it: '/modules/mcp' in the stack.
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $request = RootRewrite::apply($request, $basePath, $_SERVER['REDIRECT_URL'] ?? null);
    $app = (require __DIR__ . '/app.php')($container, $basePath);
} catch (\Throwable $e) {
    error_log('mcp: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $send($fail());
    return;
}

// What App::run() does, split so the capture ends between handling and emitting.
$send($app->handle($request));
