<?php

/*
    The request's object graph. Returns a factory rather than a container because
    three things come from index.php: this module's configuration, the GraphQL
    module's (for trust_proxy, and to build the Executor) and what is known about
    the HTTP request. The same container is given to Slim (app.php), so actions
    and middleware resolve through it.
*/

use DI\ContainerBuilder;
use FA\GraphQL\Api\Executor;
use FA\GraphQL\Config as GraphQlConfig;
use FA\Mcp\Config;
use FA\Mcp\Gateway\AccountGateway;
use FA\Mcp\Gateway\ExecutorAccountGateway;
use FA\Mcp\Gateway\ExecutorGraphQlGateway;
use FA\Mcp\Gateway\GraphQlGateway;
use FA\Mcp\Http\ErrorMiddleware;
use FA\Mcp\Http\McpAction;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\OAuth\Metadata;
use FA\Mcp\Protocol\Instructions;
use FA\Mcp\Protocol\McpServer;
use FA\Mcp\Protocol\ProtocolServer;
use FA\Mcp\Tool\SchemaToolFactory;
use FA\Mcp\Version;
use Psr\Container\ContainerInterface;

return function (Config $config, GraphQlConfig $graphqlConfig, RequestContext $request): DI\Container {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->addDefinitions([
        Config::class => $config,
        GraphQlConfig::class => $graphqlConfig,
        RequestContext::class => $request,
        // The one way into graphql (spec §3): token checks, the FrontAccounting
        // session, the schema, execution. It boots FrontAccounting on first use.
        Executor::class => static function () use ($graphqlConfig): Executor {
            return new Executor($graphqlConfig);
        },
        McpServer::class => static function () use ($config): McpServer {
            return new McpServer('frontaccounting-mcp', Version::VERSION, Instructions::TEXT, $config->debug);
        },
        ProtocolServer::class => DI\get(McpServer::class),
        SchemaToolFactory::class => static function () use ($config): SchemaToolFactory {
            return new SchemaToolFactory($config->defaultListLimit);
        },
        // (string $bearer, string $clientIp): GraphQlGateway, one per MCP request.
        'mcp.gatewayFactory' => static function (ContainerInterface $c): callable {
            return static function (string $bearer, string $clientIp) use ($c): GraphQlGateway {
                return new ExecutorGraphQlGateway($c->get(Executor::class), $bearer, $clientIp);
            };
        },
        AccountGateway::class => static function (ContainerInterface $c) use ($request): AccountGateway {
            return new ExecutorAccountGateway($c->get(Executor::class), $request->clientIp());
        },
        Metadata::class => static function () use ($config): Metadata {
            return new Metadata($config->baseUrl, $config->allowDcr, $config->allowCimd);
        },
        McpAction::class => static function (ContainerInterface $c) use ($config, $graphqlConfig): McpAction {
            return new McpAction(
                $config,
                $c->get(ProtocolServer::class),
                $c->get(SchemaToolFactory::class),
                $c->get('mcp.gatewayFactory'),
                $graphqlConfig->trustProxy
            );
        },
        ErrorMiddleware::class => static function () use ($config): ErrorMiddleware {
            return new ErrorMiddleware($config->debug);
        },
    ]);

    return $builder->build();
};
