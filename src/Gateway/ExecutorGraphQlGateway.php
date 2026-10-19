<?php

namespace FA\Mcp\Gateway;

use FA\GraphQL\Api\Executor;
use FA\GraphQL\Api\ExecutorAuthException;
use GraphQL\Type\Schema;

/**
 * The production GraphQlGateway: graphql's in-process Executor (contract 1.x),
 * bound to one request's bearer token and end-client address. The schema is
 * fetched once per instance, which lives for one request.
 */
final class ExecutorGraphQlGateway implements GraphQlGateway
{
    private Executor $executor;
    private string $bearer;
    private string $clientIp;
    private ?Schema $schema = null;

    public function __construct(Executor $executor, string $bearer, string $clientIp)
    {
        $this->executor = $executor;
        $this->bearer = $bearer;
        $this->clientIp = $clientIp;
    }

    public function execute(string $document, array $variables = [], ?string $operationName = null): array
    {
        try {
            return $this->executor->execute($this->bearer, $document, $variables, $this->clientIp, $operationName);
        } catch (ExecutorAuthException $e) {
            throw new AuthFailure($e->getReason(), $e->getMessage(), $e);
        }
    }

    public function schema(): Schema
    {
        if ($this->schema === null) {
            try {
                $this->schema = $this->executor->schema($this->bearer);
            } catch (ExecutorAuthException $e) {
                throw new AuthFailure($e->getReason(), $e->getMessage(), $e);
            }
        }
        return $this->schema;
    }
}
