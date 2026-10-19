<?php

/*
    The request's object graph. Returns a factory rather than a container because
    three things come from index.php: this module's configuration, the GraphQL
    module's (to build the Executor) and what is known about the HTTP request
    (RequestContext, built once with graphql's trust_proxy and handed to every
    action, so none of them reads forwarded headers on its own). The same
    container is given to Slim (app.php), so actions and middleware resolve
    through it.
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
use FA\Mcp\OAuth\AuthorizationServer;
use FA\Mcp\OAuth\CimdFetcher;
use FA\Mcp\OAuth\ClientRegistry;
use FA\Mcp\OAuth\Clock;
use FA\Mcp\OAuth\CurlHttpFetcher;
use FA\Mcp\OAuth\Http\AuthorizeAction;
use FA\Mcp\OAuth\Http\McpSession;
use FA\Mcp\OAuth\Http\MetadataAction;
use FA\Mcp\OAuth\Http\Pages;
use FA\Mcp\OAuth\Http\RegisterAction;
use FA\Mcp\OAuth\Http\TokenAction;
use FA\Mcp\OAuth\Metadata;
use FA\Mcp\OAuth\PdoStore;
use FA\Mcp\OAuth\Store;
use FA\Mcp\OAuth\SystemClock;
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
        // OAuth (spec §5.3, §9): its tables are in company 0's database, on a
        // PDO of mcp's own, opened only when an OAuth route needs the store.
        PdoStore::class => static function (ContainerInterface $c): PdoStore {
            $db = $c->get(Executor::class)->database(0);
            $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];
            $pdo = new \PDO($db['dsn'], $db['user'], $db['password'], $options);
            return new PdoStore($pdo, $db['prefix']);
        },
        Store::class => DI\get(PdoStore::class),
        Clock::class => static function (): Clock {
            return new SystemClock();
        },
        ClientRegistry::class => static function (ContainerInterface $c) use ($config): ClientRegistry {
            return new ClientRegistry(
                $c->get(Store::class),
                new CimdFetcher(new CurlHttpFetcher()),
                $config,
                $c->get(Clock::class)
            );
        },
        AuthorizationServer::class => static function (ContainerInterface $c) use ($config): AuthorizationServer {
            return new AuthorizationServer(
                $c->get(Store::class),
                $c->get(ClientRegistry::class),
                $c->get(AccountGateway::class),
                $c->get(Metadata::class),
                $c->get(Clock::class),
                $config
            );
        },
        MetadataAction::class => static function (ContainerInterface $c) use ($config, $request): MetadataAction {
            return new MetadataAction($config, $c->get(Metadata::class), $request);
        },
        AuthorizeAction::class => static function (ContainerInterface $c) use (
            $config,
            $graphqlConfig,
            $request
        ): AuthorizeAction {
            return new AuthorizeAction(
                $config,
                $c->get(AuthorizationServer::class),
                $c->get(AccountGateway::class),
                new Pages(),
                McpSession::key($graphqlConfig->secret),
                $c->get(Clock::class),
                $request
            );
        },
        TokenAction::class => static function (ContainerInterface $c) use ($config, $request): TokenAction {
            return new TokenAction($config, $c->get(AuthorizationServer::class), $request);
        },
        RegisterAction::class => static function (ContainerInterface $c) use ($config, $request): RegisterAction {
            return new RegisterAction($config, $c->get(ClientRegistry::class), $c->get(Clock::class), $request);
        },
        McpAction::class => static function (ContainerInterface $c) use ($config, $request): McpAction {
            return new McpAction(
                $config,
                $c->get(ProtocolServer::class),
                $c->get(SchemaToolFactory::class),
                $c->get('mcp.gatewayFactory'),
                $request
            );
        },
        ErrorMiddleware::class => static function () use ($config): ErrorMiddleware {
            return new ErrorMiddleware($config->debug);
        },
    ]);

    return $builder->build();
};
