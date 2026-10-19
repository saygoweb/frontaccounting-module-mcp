<?php

namespace FA\Mcp\Gateway;

/**
 * mcp's seam to graphql for accounts and tokens (spec §3, §4), used by the
 * OAuth flow: the companies to log in to, FA's own password check, and
 * graphql's stored, revocable machine tokens. One call handles one company.
 */
interface AccountGateway
{
    /**
     * The companies from config_db.php, for the OAuth login form.
     *
     * @return array<int,string> company id => name
     */
    public function companies(): array;

    /**
     * FA's own password check (current_user::login, so login_delay throttling
     * applies), then SA_GRAPHQL.
     *
     * @return array{status: string, login: string} status OK|BAD_CREDENTIALS|NO_API_ACCESS
     */
    public function authenticate(int $company, string $login, string $password): array;

    /**
     * Issue a stored, revocable graphql token (a graphql_machine_token row) for
     * a user who has already been authenticated.
     *
     * @param string $scope 'read' or 'write'
     * @param string $label the connection name, stored as the token's label
     * @return array{token: string, jti: string, expiresAt: int}
     * @throws AuthFailure
     */
    public function issueToken(int $company, string $login, string $scope, int $ttlSeconds, string $label): array;

    /** Revoke a token graphql issued, as graphql's Executor::revokeToken() does, returning its result. */
    public function revokeToken(int $company, string $jti): bool;

    /** True when the token's row is missing or revoked. Expiry is ignored. */
    public function tokenIsRevoked(int $company, string $jti): bool;
}
