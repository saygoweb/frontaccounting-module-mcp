<?php

namespace FA\Mcp\OAuth;

/**
 * Persistence for the OAuth tables (spec §9). Rows are associative arrays
 * whose keys are the columns; times are Unix seconds (int, or null where the
 * column is nullable); company and id are int; redirect_uris is the stored
 * JSON string; a code's resource is a string or null.
 *
 * A code and a refresh row belong to one (company, login): FA user names are
 * per company, so the pair is the identity, and graphql_jti names the graphql
 * token in that company.
 *
 * The consume/rotate/revoke methods are the race-sensitive ones: two requests
 * presenting the same code or refresh token at once must see exactly one
 * winner, so each is a single conditional write that reports whether it won.
 */
interface Store
{
    /** @return array<string, mixed>|null */
    public function findClient(string $clientId): ?array;

    /**
     * Insert, or replace every column of an existing client_id.
     *
     * @param array<string, mixed> $row
     */
    public function saveClient(array $row): void;

    public function touchClient(string $clientId, int $at): void;

    /** Clients of $kind (dcr|cimd) first stored for $ip at or after $since. */
    public function countClientsFromIpSince(string $ip, int $since, string $kind): int;

    /**
     * Delete clients last used (or, never used, created) before $olderThan,
     * except those with a refresh token still usable at $now (unexpired, not
     * rotated, not revoked); and codes expired before $olderThan.
     */
    public function pruneUnusedClients(int $olderThan, int $now): void;

    /**
     * Delete refresh rows whose expires_at is at or before $now, rotated,
     * revoked or not: none of them can be exchanged any more. At most $limit
     * rows when one is given.
     */
    public function pruneExpiredRefresh(int $now, ?int $limit = null): void;

    /** Delete codes whose expires_at is before $before; at most $limit when one is given. */
    public function pruneExpiredCodes(int $before, ?int $limit = null): void;

    /** @param array<string, mixed> $row */
    public function saveCode(array $row): void;

    /** @return array<string, mixed>|null */
    public function findCode(string $codeHash): ?array;

    /**
     * Atomically set used_at if NULL.
     *
     * @return bool true if this call consumed it
     */
    public function consumeCode(string $codeHash, int $at): bool;

    /**
     * @param array<string, mixed> $row
     * @return int the new row's id
     */
    public function saveRefresh(array $row): int;

    /** @return array<string, mixed>|null */
    public function findRefreshByHash(string $hash): ?array;

    /**
     * Atomically set rotated_at if NULL and revoked_at NULL.
     *
     * @return bool true if this call rotated it
     */
    public function rotateRefresh(int $id, int $at): bool;

    /**
     * Set revoked_at on every unrevoked row of the family.
     *
     * @return array<int, array<string, mixed>> rows of the family, after the update
     */
    public function revokeFamily(string $family, int $at): array;

    /** Delete every refresh row of one user (company, login). */
    public function deleteRefreshForAccount(int $company, string $login): void;

    /** Delete one refresh row: one saved for a refresh that then lost its race. */
    public function deleteRefresh(int $id): void;

    /**
     * Every graphql token a refresh row (any state) points at, once each, for
     * revoking them all on uninstall.
     *
     * @return array<int, array{company: int, graphql_jti: string}> ordered by both
     */
    public function graphQlTokens(): array;
}
