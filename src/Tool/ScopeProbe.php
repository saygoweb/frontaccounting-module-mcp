<?php

namespace FA\Mcp\Tool;

use FA\Mcp\Gateway\GraphQlGateway;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The connection's token scope, 'read' or 'write', learned with one
 * `me { scope }` query and cached (spec §6.5). It fails closed: only an
 * explicit 'write' answer is 'write', and a GraphQL error, missing data or
 * any other answer is 'read'. The one exception is a schema with no
 * `me { scope }` at all (an older graphql, whose tokens are all write),
 * which is 'write' without a query.
 */
final class ScopeProbe
{
    public const READ = 'read';
    public const WRITE = 'write';

    private const DOCUMENT = '{ me { scope } }';

    private GraphQlGateway $gql;
    private ?string $scope = null;

    public function __construct(GraphQlGateway $gql)
    {
        $this->gql = $gql;
    }

    /** @throws \FA\Mcp\Gateway\AuthFailure from the gateway */
    public function scope(): string
    {
        if ($this->scope === null) {
            if (!$this->schemaHasScope()) {
                $this->scope = self::WRITE;
            } else {
                $envelope = $this->gql->execute(self::DOCUMENT);
                $scope = $envelope['data']['me']['scope'] ?? null;
                $this->scope = $scope === self::WRITE ? self::WRITE : self::READ;
            }
        }
        return $this->scope;
    }

    /** Whether the schema has `Query.me.scope`. */
    private function schemaHasScope(): bool
    {
        $query = $this->gql->schema()->getQueryType();
        if ($query === null || !$query->hasField('me')) {
            return false;
        }
        $viewer = Type::getNamedType($query->getField('me')->getType());
        return $viewer instanceof ObjectType && $viewer->hasField('scope');
    }
}
