<?php

namespace FA\Mcp\Gateway;

/**
 * mcp's seam to graphql for running documents (spec §3): one of exactly two,
 * so all other code is unit-testable with an in-memory double. An
 * implementation is bound to one request's bearer token; the production one
 * calls FA\GraphQL\Api\Executor in-process.
 */
interface GraphQlGateway
{
    /**
     * Run one document as the bearer's holder, exactly as POST /modules/graphql/:
     * token verified, company opened, FA login, SA_GRAPHQL, scope (a read token
     * may not run a mutation), depth/complexity, execute, ErrorFormatter, FA
     * warnings in extensions.
     *
     * @param array<string,mixed> $variables
     * @return array<string,mixed> the GraphQL envelope: data?, errors?, extensions?
     * @throws AuthFailure UNAUTHENTICATED (401) or FORBIDDEN (403)
     */
    public function execute(string $document, array $variables = [], ?string $operationName = null): array;

    /**
     * The bearer's effective schema: core plus the extensions active in its
     * company. Same authentication as execute().
     *
     * @throws AuthFailure UNAUTHENTICATED (401) or FORBIDDEN (403)
     */
    public function schema(): \GraphQL\Type\Schema;
}
