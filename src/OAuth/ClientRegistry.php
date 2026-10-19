<?php

namespace FA\Mcp\OAuth;

use FA\Mcp\Config;

/**
 * The OAuth clients the module knows: those registered through DCR (RFC 7591)
 * and those whose client_id is a metadata-document URL (CIMD), cached in the
 * same table. Public clients only - PKCE is what binds a code to its client.
 */
final class ClientRegistry
{
    public const DEFAULT_NAME = 'MCP client';
    public const UNUSED_CLIENT_SECONDS = 2592000; // 30 days
    public const PRUNE_ONE_IN = 50;
    public const MAX_CLIENT_ID_LENGTH = 255; // mcp_oauth_client.client_id
    public const FAILED_FETCH_SECONDS = 300;
    public const MAX_REDIRECT_URIS = 10;
    public const MAX_REDIRECT_URI_BYTES = 512;
    public const MAX_CLIENT_NAME_BYTES = 255; // mcp_oauth_client.client_name

    private Store $store;
    private CimdFetcher $cimd;
    private Config $config;
    private Clock $clock;
    /** @var callable(): bool whether this chance to prune is taken */
    private $pruneSample;

    /**
     * @param callable|null $pruneSample callable(): bool, asked at each chance
     *                                   to prune; null takes one in PRUNE_ONE_IN
     */
    public function __construct(
        Store $store,
        CimdFetcher $cimd,
        Config $config,
        Clock $clock,
        ?callable $pruneSample = null
    ) {
        $this->store = $store;
        $this->cimd = $cimd;
        $this->config = $config;
        $this->clock = $clock;
        $this->pruneSample = $pruneSample ?? function (): bool {
            return random_int(1, self::PRUNE_ONE_IN) === 1;
        };
    }

    /**
     * DCR.
     *
     * @param array<string, mixed> $metadata the RFC 7591 registration request
     * @throws OAuthError invalid_request (disabled; 429 when rate limited),
     *                    invalid_redirect_uri, invalid_client_metadata
     */
    public function register(array $metadata, string $sourceIp): Client
    {
        if (!$this->config->allowDcr) {
            throw new OAuthError('invalid_request', 'Dynamic client registration is disabled', 400);
        }

        $now = $this->clock->now();

        $this->maybePrune();

        $recent = $this->store->countClientsFromIpSince($sourceIp, $now - 3600, 'dcr');
        if ($recent >= $this->config->dcrRateLimit) {
            throw new OAuthError('invalid_request', 'Too many registrations', 429);
        }

        $uris = isset($metadata['redirect_uris']) ? $metadata['redirect_uris'] : null;
        if (!is_array($uris) || !$uris) {
            throw new OAuthError('invalid_redirect_uri', 'redirect_uris must be a non-empty array');
        }
        if (count($uris) > self::MAX_REDIRECT_URIS) {
            throw new OAuthError(
                'invalid_client_metadata',
                'At most ' . self::MAX_REDIRECT_URIS . ' redirect URIs may be registered'
            );
        }
        foreach ($uris as $uri) {
            if (is_string($uri) && strlen($uri) > self::MAX_REDIRECT_URI_BYTES) {
                throw new OAuthError(
                    'invalid_client_metadata',
                    'Each redirect URI must be at most ' . self::MAX_REDIRECT_URI_BYTES . ' bytes'
                );
            }
            if (!is_string($uri) || !RedirectUriPolicy::isAcceptable($uri)) {
                throw new OAuthError(
                    'invalid_redirect_uri',
                    'Each redirect URI must be https, or http on a loopback address, without a fragment'
                );
            }
        }

        $name = self::DEFAULT_NAME;
        if (array_key_exists('client_name', $metadata) && $metadata['client_name'] !== null) {
            if (
                !is_string($metadata['client_name'])
                || strlen($metadata['client_name']) > self::MAX_CLIENT_NAME_BYTES
            ) {
                throw new OAuthError(
                    'invalid_client_metadata',
                    'client_name must be a string of at most ' . self::MAX_CLIENT_NAME_BYTES . ' bytes'
                );
            }
            if (trim($metadata['client_name']) !== '') {
                $name = trim($metadata['client_name']);
            }
        }

        if (
            array_key_exists('token_endpoint_auth_method', $metadata)
            && $metadata['token_endpoint_auth_method'] !== 'none'
        ) {
            throw new OAuthError(
                'invalid_client_metadata',
                'Only public clients are supported: token_endpoint_auth_method must be none'
            );
        }

        $client = new Client(bin2hex(random_bytes(16)), 'dcr', $name, array_values($uris));
        $this->store->saveClient([
            'client_id' => $client->clientId(),
            'kind' => 'dcr',
            'client_name' => $client->name(),
            'redirect_uris' => (string)json_encode($client->redirectUris(), JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'last_used_at' => null,
            'fetched_at' => null,
            'fetch_ok_at' => null,
            'source_ip' => $sourceIp,
        ]);
        return $client;
    }

    /**
     * Delete clients unused for 30 days that hold no usable refresh token,
     * codes long expired, and refresh rows past their expiry. Run on one call
     * in PRUNE_ONE_IN of register(), of CIMD resolution at /authorize and of
     * resolveForToken() at /token - so it runs whether or not DCR is allowed.
     */
    public function prune(): void
    {
        $now = $this->clock->now();
        $this->store->pruneUnusedClients($now - self::UNUSED_CLIENT_SECONDS, $now);
        $this->store->pruneExpiredRefresh($now);
    }

    /**
     * For /oauth/authorize: a DCR id from the store, or an https URL resolved
     * through CIMD (cached cimd_cache_ttl), which may fetch the document.
     *
     * @param string $sourceIp the requester's address: a URL never seen
     *                         before costs a fetch, and each address may
     *                         make dcr_rate_limit of those an hour
     * @throws OAuthError invalid_client
     */
    public function resolve(string $clientId, string $sourceIp): Client
    {
        if (stripos($clientId, 'https://') === 0) {
            return $this->resolveCimd($clientId, $sourceIp);
        }
        return $this->resolveDcr($clientId);
    }

    /**
     * For /oauth/token: the client from the store only, never fetched. A CIMD
     * client gets there by /authorize first, so the token endpoint - which
     * anyone can call with any client_id - cannot be used to make the server
     * fetch URLs. The cache TTL is ignored: it bounds how stale /authorize's
     * view of the document may be, and a refresh months after sign-in must
     * still find its client.
     *
     * @throws OAuthError invalid_client
     */
    public function resolveForToken(string $clientId): Client
    {
        $this->maybePrune();
        if (stripos($clientId, 'https://') !== 0) {
            return $this->resolveDcr($clientId);
        }

        $this->checkCimdId($clientId);
        $row = $this->store->findClient($clientId);
        if ($row === null || $row['kind'] !== 'cimd' || self::isFailedFetch($row)) {
            throw new OAuthError('invalid_client', 'Unknown client', 401);
        }
        return self::fromRow($row);
    }

    /**
     * Anyone can register or name a client, so the table needs a sweep nobody
     * has to schedule. One call in fifty is often enough to keep it small and
     * rare enough that the calls stay cheap.
     */
    private function maybePrune(): void
    {
        if (call_user_func($this->pruneSample) === true) {
            $this->prune();
        }
    }

    public function touch(string $clientId): void
    {
        $this->store->touchClient($clientId, $this->clock->now());
    }

    private function resolveDcr(string $clientId): Client
    {
        $row = $clientId === '' ? null : $this->store->findClient($clientId);
        if ($row === null || $row['kind'] !== 'dcr') {
            throw new OAuthError('invalid_client', 'Unknown client', 401);
        }
        return self::fromRow($row);
    }

    /** @throws OAuthError invalid_client when the URL is not one CIMD may use */
    private function checkCimdId(string $url): void
    {
        if (!$this->config->allowCimd) {
            throw new OAuthError('invalid_client', 'Client ID metadata documents are not accepted', 401);
        }
        if (strlen($url) > self::MAX_CLIENT_ID_LENGTH) {
            throw new OAuthError('invalid_client', 'The client_id is too long', 401);
        }
        if (CimdFetcher::hostOf($url) === null) {
            throw new OAuthError('invalid_client', 'The client_id must be an https URL with a path', 401);
        }
    }

    private function resolveCimd(string $url, string $sourceIp): Client
    {
        $this->checkCimdId($url);
        $this->maybePrune();

        $now = $this->clock->now();
        $row = $this->store->findClient($url);

        // Anyone can send any URL, and each new one makes the server fetch it,
        // so new ones are counted per address as registrations are. A URL
        // already stored - fetched, or failed and remembered - refetches at
        // most once per cache period and is not counted.
        if (
            $row === null
            && $this->store->countClientsFromIpSince($sourceIp, $now - 3600, 'cimd') >= $this->config->dcrRateLimit
        ) {
            throw new OAuthError('invalid_client', 'Too many client lookups', 401);
        }
        $ip = $row !== null ? $row['source_ip'] : $sourceIp;
        if ($row !== null && $row['kind'] === 'cimd' && $row['fetched_at'] !== null) {
            $fetchedAt = (int)$row['fetched_at'];
            if (self::isFailedFetch($row)) {
                if ($fetchedAt + self::FAILED_FETCH_SECONDS > $now) {
                    throw self::unusable();
                }
            } elseif ($fetchedAt + $this->config->cimdCacheTtl > $now) {
                // Fresh, or inside the backoff after a failed refetch.
                if (!$this->withinGrace($row, $now)) {
                    throw self::unusable();
                }
                return self::fromRow($row);
            }
        }
        $known = $row !== null && $row['kind'] === 'cimd' && !self::isFailedFetch($row);

        try {
            $client = $this->cimd->fetch($url);
        } catch (OAuthError $e) {
            // Remember the failure for a while, so that a bad client_id sent
            // again and again costs one fetch per FAILED_FETCH_SECONDS, not
            // one per request. A client already known keeps its cached
            // document for that while instead: its host being briefly down
            // must not sign out everyone using it. Only briefly, though: once
            // the last good fetch is two cache periods old, the document is
            // no longer used at /authorize.
            if ($known && $row !== null) {
                $row['fetched_at'] = $now - $this->config->cimdCacheTtl + self::FAILED_FETCH_SECONDS;
                $this->store->saveClient($row);
                if (!$this->withinGrace($row, $now)) {
                    throw self::unusable($e);
                }
                return self::fromRow($row);
            }
            $this->store->saveClient([
                'client_id' => $url,
                'kind' => 'cimd',
                'client_name' => '',
                'redirect_uris' => '[]',
                'created_at' => $row !== null ? (int)$row['created_at'] : $now,
                'last_used_at' => null,
                'fetched_at' => $now,
                'fetch_ok_at' => null,
                'source_ip' => $ip,
            ]);
            throw $e;
        }

        $this->store->saveClient([
            'client_id' => $url,
            'kind' => 'cimd',
            'client_name' => $client->name(),
            'redirect_uris' => (string)json_encode($client->redirectUris(), JSON_UNESCAPED_SLASHES),
            'created_at' => $row !== null ? (int)$row['created_at'] : $now,
            'last_used_at' => $row !== null && $row['last_used_at'] !== null ? (int)$row['last_used_at'] : null,
            'fetched_at' => $now,
            'fetch_ok_at' => $now,
            'source_ip' => $ip,
        ]);
        return $client;
    }

    /**
     * Whether a cached CIMD document may still be used: its last successful
     * fetch is younger than two cache periods.
     *
     * @param array<string, mixed> $row
     */
    private function withinGrace(array $row, int $now): bool
    {
        $ok = $row['fetch_ok_at'] ?? null;
        return $ok !== null && (int)$ok + 2 * $this->config->cimdCacheTtl > $now;
    }

    private static function unusable(?\Throwable $previous = null): OAuthError
    {
        return new OAuthError(
            'invalid_client',
            'The client metadata document could not be used; try again later',
            401,
            false,
            $previous
        );
    }

    /** A CIMD row recording a failed fetch: no redirect URIs, which a good document never has. */
    /** @param array<string, mixed> $row */
    private static function isFailedFetch(array $row): bool
    {
        $uris = json_decode((string)$row['redirect_uris'], true);
        return !is_array($uris) || !$uris;
    }

    /** @param array<string, mixed> $row */
    private static function fromRow(array $row): Client
    {
        $uris = json_decode((string)$row['redirect_uris'], true);
        return new Client(
            (string)$row['client_id'],
            (string)$row['kind'],
            (string)$row['client_name'],
            is_array($uris) ? $uris : []
        );
    }
}
