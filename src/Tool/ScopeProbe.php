<?php

namespace FA\Mcp\Tool;

use FA\Mcp\Gateway\GraphQlGateway;

/**
 * The connection's token scope, 'read' or 'write', learned with one
 * `me { scope }` query and cached (spec §6.5). Anything but an answer counts
 * as 'write': only the listing depends on it, since graphql refuses a read
 * token's mutation itself.
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
            $envelope = $this->gql->execute(self::DOCUMENT);
            $scope = $envelope['data']['me']['scope'] ?? null;
            $this->scope = is_string($scope) && $scope !== '' ? $scope : self::WRITE;
        }
        return $this->scope;
    }
}
