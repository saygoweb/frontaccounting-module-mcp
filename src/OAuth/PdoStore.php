<?php

namespace FA\Mcp\OAuth;

/**
 * The Store over company 0's database (spec §9), on mcp's own PDO, with that
 * company's table prefix. Every statement is prepared. Times are stored as
 * UTC DATETIME ('Y-m-d H:i:s') and read back as Unix seconds; a code's
 * missing resource is stored as ''.
 *
 * Every conditional write is one UPDATE whose WHERE clause carries the
 * condition, so InnoDB's row lock decides a race and rowCount() says who
 * won; nothing is read and then written.
 *
 * Covered by the integration suite (PdoStoreTest), not the unit suite:
 * InMemoryStore stands in for it there, and both pass StoreContractTest.
 */
final class PdoStore implements Store
{
    private const CLIENT_COLUMNS = 'client_id, kind, client_name, redirect_uris, created_at, last_used_at,'
        . ' fetched_at, fetch_ok_at, source_ip';
    private const CODE_COLUMNS = 'code_hash, client_id, company, login, redirect_uri, code_challenge, scope,'
        . ' resource, expires_at, used_at';
    private const REFRESH_COLUMNS = 'id, token_hash, family, client_id, company, login, scope, graphql_jti,'
        . ' expires_at, rotated_at, revoked_at, created_at';

    private \PDO $pdo;
    private string $client;
    private string $code;
    private string $refresh;

    /**
     * @param \PDO $pdo a connection to company 0's database; switched to ERRMODE_EXCEPTION
     * @param string $prefix company 0's table prefix, e.g. '0_'
     */
    public function __construct(\PDO $pdo, string $prefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
            throw new \InvalidArgumentException('The table prefix may hold only letters, digits and underscores.');
        }
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo = $pdo;
        $this->client = '`' . $prefix . 'mcp_oauth_client`';
        $this->code = '`' . $prefix . 'mcp_oauth_code`';
        $this->refresh = '`' . $prefix . 'mcp_oauth_refresh`';
    }

    public function findClient(string $clientId): ?array
    {
        $row = $this->one(
            'SELECT ' . self::CLIENT_COLUMNS . " FROM {$this->client} WHERE client_id = ?",
            [$clientId]
        );
        return $row === null ? null : self::clientFromDb($row);
    }

    public function saveClient(array $row): void
    {
        $this->run(
            "INSERT INTO {$this->client} (" . self::CLIENT_COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    kind = VALUES(kind), client_name = VALUES(client_name),
                    redirect_uris = VALUES(redirect_uris), created_at = VALUES(created_at),
                    last_used_at = VALUES(last_used_at), fetched_at = VALUES(fetched_at),
                    fetch_ok_at = VALUES(fetch_ok_at), source_ip = VALUES(source_ip)',
            [
                (string)$row['client_id'], (string)$row['kind'], (string)$row['client_name'],
                (string)$row['redirect_uris'], self::toDb($row['created_at']), self::toDb($row['last_used_at']),
                self::toDb($row['fetched_at']), self::toDb($row['fetch_ok_at'] ?? null), (string)$row['source_ip'],
            ]
        );
    }

    public function touchClient(string $clientId, int $at): void
    {
        $this->run("UPDATE {$this->client} SET last_used_at = ? WHERE client_id = ?", [self::toDb($at), $clientId]);
    }

    public function countClientsFromIpSince(string $ip, int $since, string $kind): int
    {
        $row = $this->one(
            "SELECT COUNT(*) AS c FROM {$this->client} WHERE source_ip = ? AND created_at >= ? AND kind = ?",
            [$ip, self::toDb($since), $kind]
        );
        return $row === null ? 0 : (int)$row['c'];
    }

    public function pruneUnusedClients(int $olderThan, int $now): void
    {
        $this->run(
            "DELETE FROM {$this->client}
                WHERE COALESCE(last_used_at, created_at) < ?
                AND NOT EXISTS (
                    SELECT 1 FROM {$this->refresh} r
                    WHERE r.client_id = {$this->client}.client_id
                    AND r.expires_at > ? AND r.rotated_at IS NULL AND r.revoked_at IS NULL
                )",
            [self::toDb($olderThan), self::toDb($now)]
        );
        $this->run("DELETE FROM {$this->code} WHERE expires_at < ?", [self::toDb($olderThan)]);
    }

    public function pruneExpiredRefresh(int $now, ?int $limit = null): void
    {
        $this->run("DELETE FROM {$this->refresh} WHERE expires_at <= ?" . self::limit($limit), [self::toDb($now)]);
    }

    public function pruneExpiredCodes(int $before, ?int $limit = null): void
    {
        $this->run("DELETE FROM {$this->code} WHERE expires_at < ?" . self::limit($limit), [self::toDb($before)]);
    }

    public function saveCode(array $row): void
    {
        $this->run(
            "INSERT INTO {$this->code} (" . self::CODE_COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string)$row['code_hash'], (string)$row['client_id'], (int)$row['company'], (string)$row['login'],
                (string)$row['redirect_uri'], (string)$row['code_challenge'], (string)$row['scope'],
                (string)$row['resource'], self::toDb($row['expires_at']), self::toDb($row['used_at']),
            ]
        );
    }

    public function findCode(string $codeHash): ?array
    {
        $row = $this->one('SELECT ' . self::CODE_COLUMNS . " FROM {$this->code} WHERE code_hash = ?", [$codeHash]);
        if ($row === null) {
            return null;
        }
        $row['company'] = (int)$row['company'];
        $row['resource'] = $row['resource'] === '' || $row['resource'] === null ? null : (string)$row['resource'];
        $row['expires_at'] = (int)self::fromDb($row['expires_at']);
        $row['used_at'] = self::fromDb($row['used_at']);
        return $row;
    }

    public function consumeCode(string $codeHash, int $at): bool
    {
        return $this->run(
            "UPDATE {$this->code} SET used_at = ? WHERE code_hash = ? AND used_at IS NULL",
            [self::toDb($at), $codeHash]
        )->rowCount() === 1;
    }

    public function saveRefresh(array $row): int
    {
        $this->run(
            "INSERT INTO {$this->refresh} (
                    token_hash, family, client_id, company, login, scope, graphql_jti,
                    expires_at, rotated_at, revoked_at, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (string)$row['token_hash'], (string)$row['family'], (string)$row['client_id'], (int)$row['company'],
                (string)$row['login'], (string)$row['scope'], (string)$row['graphql_jti'],
                self::toDb($row['expires_at']), self::toDb($row['rotated_at']), self::toDb($row['revoked_at']),
                self::toDb($row['created_at']),
            ]
        );
        return (int)$this->pdo->lastInsertId();
    }

    public function findRefreshByHash(string $hash): ?array
    {
        $row = $this->one(
            'SELECT ' . self::REFRESH_COLUMNS . " FROM {$this->refresh} WHERE token_hash = ?",
            [$hash]
        );
        return $row === null ? null : self::refreshFromDb($row);
    }

    public function rotateRefresh(int $id, int $at): bool
    {
        return $this->run(
            "UPDATE {$this->refresh} SET rotated_at = ? WHERE id = ? AND rotated_at IS NULL AND revoked_at IS NULL",
            [self::toDb($at), $id]
        )->rowCount() === 1;
    }

    public function revokeFamily(string $family, int $at): array
    {
        $this->run(
            "UPDATE {$this->refresh} SET revoked_at = ? WHERE family = ? AND revoked_at IS NULL",
            [self::toDb($at), $family]
        );
        $rows = $this->run(
            'SELECT ' . self::REFRESH_COLUMNS . " FROM {$this->refresh} WHERE family = ? ORDER BY id",
            [$family]
        )->fetchAll(\PDO::FETCH_ASSOC);
        return array_map([self::class, 'refreshFromDb'], $rows);
    }

    public function deleteRefreshForAccount(int $company, string $login): void
    {
        $this->run("DELETE FROM {$this->refresh} WHERE company = ? AND login = ?", [$company, $login]);
    }

    public function deleteRefresh(int $id): void
    {
        $this->run("DELETE FROM {$this->refresh} WHERE id = ?", [$id]);
    }

    public function graphQlTokens(): array
    {
        $rows = $this->run(
            "SELECT DISTINCT company, graphql_jti FROM {$this->refresh} ORDER BY company, graphql_jti",
            []
        )->fetchAll(\PDO::FETCH_ASSOC);
        $tokens = [];
        foreach ($rows as $row) {
            $tokens[] = ['company' => (int)$row['company'], 'graphql_jti' => (string)$row['graphql_jti']];
        }
        return $tokens;
    }

    /** @param array<int, mixed> $bind */
    private function run(string $sql, array $bind): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bind);
        return $statement;
    }

    /**
     * @param array<int, mixed> $bind
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $bind): ?array
    {
        $row = $this->run($sql, $bind)->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** A LIMIT clause; the limit is an int, so it is written into the statement. */
    private static function limit(?int $limit): string
    {
        return $limit === null ? '' : ' LIMIT ' . max(0, $limit);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function clientFromDb(array $row): array
    {
        $row['created_at'] = (int)self::fromDb($row['created_at']);
        $row['last_used_at'] = self::fromDb($row['last_used_at']);
        $row['fetched_at'] = self::fromDb($row['fetched_at']);
        $row['fetch_ok_at'] = self::fromDb($row['fetch_ok_at']);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function refreshFromDb(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['company'] = (int)$row['company'];
        $row['expires_at'] = (int)self::fromDb($row['expires_at']);
        $row['rotated_at'] = self::fromDb($row['rotated_at']);
        $row['revoked_at'] = self::fromDb($row['revoked_at']);
        $row['created_at'] = (int)self::fromDb($row['created_at']);
        return $row;
    }

    /** @param mixed $time Unix seconds or null */
    private static function toDb($time): ?string
    {
        return $time === null ? null : gmdate('Y-m-d H:i:s', (int)$time);
    }

    /** @param mixed $value a DATETIME read back, or null */
    private static function fromDb($value): ?int
    {
        if ($value === null) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string)$value, new \DateTimeZone('UTC'));
        if ($time === false) {
            throw new \UnexpectedValueException(sprintf('Unreadable DATETIME "%s".', (string)$value));
        }
        return $time->getTimestamp();
    }
}
