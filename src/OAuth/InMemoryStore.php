<?php

namespace FA\Mcp\OAuth;

/**
 * The Store in arrays, for the unit suite. Its semantics are PdoStore's: the
 * conditional writes succeed once, and rows read back as they were saved.
 */
final class InMemoryStore implements Store
{
    /** @var array<string, array<string, mixed>> client_id => row */
    private array $clients = [];
    /** @var array<string, array<string, mixed>> code_hash => row */
    private array $codes = [];
    /** @var array<int, array<string, mixed>> id => row */
    private array $refresh = [];
    private int $nextRefreshId = 1;

    /** @var callable|null run with the row before saveRefresh() writes it; a test throws from it */
    public $beforeSaveRefresh;

    public function findClient(string $clientId): ?array
    {
        return isset($this->clients[$clientId]) ? $this->clients[$clientId] : null;
    }

    public function saveClient(array $row): void
    {
        $this->clients[(string)$row['client_id']] = $row;
    }

    public function touchClient(string $clientId, int $at): void
    {
        if (isset($this->clients[$clientId])) {
            $this->clients[$clientId]['last_used_at'] = $at;
        }
    }

    public function countClientsFromIpSince(string $ip, int $since, string $kind): int
    {
        $n = 0;
        foreach ($this->clients as $row) {
            if ($row['kind'] === $kind && $row['source_ip'] === $ip && (int)$row['created_at'] >= $since) {
                $n++;
            }
        }
        return $n;
    }

    public function pruneUnusedClients(int $olderThan, int $now): void
    {
        $live = [];
        foreach ($this->refresh as $row) {
            if ((int)$row['expires_at'] > $now && $row['rotated_at'] === null && $row['revoked_at'] === null) {
                $live[(string)$row['client_id']] = true;
            }
        }
        foreach ($this->clients as $id => $row) {
            $last = $row['last_used_at'] !== null ? (int)$row['last_used_at'] : (int)$row['created_at'];
            if ($last < $olderThan && !isset($live[$id])) {
                unset($this->clients[$id]);
            }
        }
        foreach ($this->codes as $hash => $row) {
            if ((int)$row['expires_at'] < $olderThan) {
                unset($this->codes[$hash]);
            }
        }
    }

    public function pruneExpiredRefresh(int $now, ?int $limit = null): void
    {
        $deleted = 0;
        foreach ($this->refresh as $id => $row) {
            if ($limit !== null && $deleted >= $limit) {
                return;
            }
            if ((int)$row['expires_at'] <= $now) {
                unset($this->refresh[$id]);
                $deleted++;
            }
        }
    }

    public function pruneExpiredCodes(int $before, ?int $limit = null): void
    {
        $deleted = 0;
        foreach ($this->codes as $hash => $row) {
            if ($limit !== null && $deleted >= $limit) {
                return;
            }
            if ((int)$row['expires_at'] < $before) {
                unset($this->codes[$hash]);
                $deleted++;
            }
        }
    }

    public function saveCode(array $row): void
    {
        $this->codes[(string)$row['code_hash']] = $row + ['replayed_at' => null];
    }

    public function findCode(string $codeHash): ?array
    {
        return isset($this->codes[$codeHash]) ? $this->codes[$codeHash] : null;
    }

    public function consumeCode(string $codeHash, int $at): bool
    {
        if (!isset($this->codes[$codeHash]) || $this->codes[$codeHash]['used_at'] !== null) {
            return false;
        }
        $this->codes[$codeHash]['used_at'] = $at;
        return true;
    }

    public function markCodeReplayed(string $codeHash, int $at): void
    {
        if (isset($this->codes[$codeHash]) && ($this->codes[$codeHash]['replayed_at'] ?? null) === null) {
            $this->codes[$codeHash]['replayed_at'] = $at;
        }
    }

    public function saveRefresh(array $row): int
    {
        if ($this->beforeSaveRefresh !== null) {
            call_user_func($this->beforeSaveRefresh, $row);
        }
        $id = $this->nextRefreshId++;
        $row['id'] = $id;
        $this->refresh[$id] = $row;
        return $id;
    }

    public function findRefreshByHash(string $hash): ?array
    {
        foreach ($this->refresh as $row) {
            if ($row['token_hash'] === $hash) {
                return $row;
            }
        }
        return null;
    }

    public function rotateRefresh(int $id, int $at): bool
    {
        if (
            !isset($this->refresh[$id])
            || $this->refresh[$id]['rotated_at'] !== null
            || $this->refresh[$id]['revoked_at'] !== null
        ) {
            return false;
        }
        $this->refresh[$id]['rotated_at'] = $at;
        return true;
    }

    public function revokeFamily(string $family, int $at): array
    {
        $rows = [];
        foreach ($this->refresh as $id => $row) {
            if ($row['family'] !== $family) {
                continue;
            }
            if ($row['revoked_at'] === null) {
                $this->refresh[$id]['revoked_at'] = $at;
            }
            $rows[] = $this->refresh[$id];
        }
        return $rows;
    }

    public function deleteRefreshForAccount(int $company, string $login): void
    {
        foreach ($this->refresh as $id => $row) {
            if ((int)$row['company'] === $company && $row['login'] === $login) {
                unset($this->refresh[$id]);
            }
        }
    }

    public function graphQlTokens(): array
    {
        $tokens = [];
        foreach ($this->refresh as $row) {
            $key = (int)$row['company'] . ':' . (string)$row['graphql_jti'];
            $tokens[$key] = ['company' => (int)$row['company'], 'graphql_jti' => (string)$row['graphql_jti']];
        }
        usort($tokens, function (array $a, array $b): int {
            return [$a['company'], $a['graphql_jti']] <=> [$b['company'], $b['graphql_jti']];
        });
        return $tokens;
    }
}
