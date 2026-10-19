<?php

namespace FA\Mcp\OAuth;

use FA\Mcp\Config;
use FA\Mcp\Gateway\AccountGateway;
use FA\Mcp\Gateway\AuthFailure;

/**
 * The OAuth 2.1 authorization server's decisions, without HTTP (spec §5.3, §5.4).
 *
 * The access token it hands out is an ordinary graphql token, issued through
 * AccountGateway as a graphql_machine_token row, so bin/fa-token lists and
 * revokes it like any other. What this class adds is the refresh token:
 * opaque, stored hashed, rotated on every use, and grouped in a family per
 * authorization code so that a replayed code or a replayed refresh token
 * kills everything that came from the same grant.
 *
 * A grant is for one FA user, (company, login): FA user names are per
 * company, and the company rides on the code and on every refresh row.
 */
final class AuthorizationServer
{
    public const CODE_TTL = 60;
    public const TOKEN_LABEL_PREFIX = 'Claude (MCP) – ';
    public const TOKEN_LABEL_MAX_BYTES = 255;

    /** Rows each token request prunes, of expired codes and of expired refresh rows. */
    public const PRUNE_LIMIT = 100;

    /**
     * How long a code row outlives its expiry. A used code presented again
     * revokes its family even after it has expired, which needs the row.
     */
    public const USED_CODE_KEPT_SECONDS = 86400;

    private Store $store;
    private ClientRegistry $clients;
    private AccountGateway $accounts;
    private Metadata $metadata;
    private Clock $clock;
    private Config $config;

    public function __construct(
        Store $store,
        ClientRegistry $clients,
        AccountGateway $accounts,
        Metadata $metadata,
        Clock $clock,
        Config $config
    ) {
        $this->store = $store;
        $this->clients = $clients;
        $this->accounts = $accounts;
        $this->metadata = $metadata;
        $this->clock = $clock;
        $this->config = $config;
    }

    /**
     * Validate an /authorize request before any redirect.
     *
     * The client and redirect URI are checked first. Until both hold, an
     * error is not redirectable: the caller shows it as a page.
     *
     * @param array<string, mixed> $query the request's query parameters
     * @param string $sourceIp the requester's address, which ClientRegistry::resolve() rate-limits by
     * @return array{client: Client, redirectUri: string, state: ?string, codeChallenge: string,
     *               scope: string, resource: ?string} scope is 'read' or 'write', to preselect on consent
     * @throws OAuthError
     */
    public function validateAuthorizeRequest(array $query, string $sourceIp): array
    {
        $clientId = self::param($query, 'client_id');
        if ($clientId === '') {
            throw new OAuthError('invalid_request', 'client_id is required');
        }
        $client = $this->clients->resolve($clientId, $sourceIp);

        $redirectUri = self::param($query, 'redirect_uri');
        if ($redirectUri === '') {
            throw new OAuthError('invalid_request', 'redirect_uri is required');
        }
        if (!RedirectUriPolicy::matches($redirectUri, $client->redirectUris())) {
            throw new OAuthError('invalid_request', 'redirect_uri is not registered for this client');
        }

        if (self::param($query, 'response_type') !== 'code') {
            throw new OAuthError('unsupported_response_type', 'response_type must be code', 400, true);
        }
        if (self::param($query, 'code_challenge_method') !== 'S256') {
            throw new OAuthError('invalid_request', 'code_challenge_method must be S256', 400, true);
        }
        $challenge = self::param($query, 'code_challenge');
        if (!Pkce::isWellFormedChallenge($challenge)) {
            throw new OAuthError('invalid_request', 'code_challenge is missing or malformed', 400, true);
        }
        $resource = self::param($query, 'resource');
        if ($resource !== '' && !self::sameResource($resource, $this->metadata->resource())) {
            throw new OAuthError('invalid_target', 'resource is not this server', 400, true);
        }

        $state = isset($query['state']) && is_string($query['state']) ? $query['state'] : null;
        $scope = isset($query['scope']) && is_string($query['scope']) ? $query['scope'] : null;

        return [
            'client' => $client,
            'redirectUri' => $redirectUri,
            'state' => $state,
            'codeChallenge' => $challenge,
            'scope' => self::normaliseScope($scope),
            'resource' => $resource !== '' ? $resource : null,
        ];
    }

    /**
     * The user allowed: mint a 60 s single-use code, stored only as its hash.
     *
     * @param array<string, mixed> $validated validateAuthorizeRequest()'s result
     * @param string $scope 'read' or 'write', as chosen on the consent page
     * @return string the code
     * @throws \InvalidArgumentException for any other scope
     */
    public function issueCode(array $validated, int $company, string $login, string $scope): string
    {
        if (!in_array($scope, Metadata::SCOPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown MCP scope "%s".', $scope));
        }

        /** @var Client $client */
        $client = $validated['client'];
        $code = bin2hex(random_bytes(32));
        $this->store->saveCode([
            'code_hash' => hash('sha256', $code),
            'client_id' => $client->clientId(),
            'company' => $company,
            'login' => $login,
            'redirect_uri' => (string)$validated['redirectUri'],
            'code_challenge' => (string)$validated['codeChallenge'],
            'scope' => $scope,
            'resource' => $validated['resource'],
            'expires_at' => $this->clock->now() + self::CODE_TTL,
            'used_at' => null,
        ]);
        return $code;
    }

    /**
     * POST /oauth/token. Each call first prunes a bounded number of expired
     * codes and refresh rows (spec §5.4).
     *
     * @param array<string, mixed> $post the form-encoded request body
     * @return array<string, mixed> the token response
     * @throws OAuthError
     */
    public function token(array $post): array
    {
        $this->pruneExpired();

        $grantType = self::param($post, 'grant_type');
        if ($grantType === 'authorization_code') {
            return $this->exchangeCode($post);
        }
        if ($grantType === 'refresh_token') {
            return $this->refresh($post);
        }
        if ($grantType === '') {
            throw new OAuthError('invalid_request', 'grant_type is required');
        }
        throw new OAuthError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token');
    }

    /** Delete the refresh rows of one user (company, login), e.g. the user was deleted. */
    public function forgetAccount(int $company, string $login): void
    {
        $this->store->deleteRefreshForAccount($company, $login);
    }

    private function pruneExpired(): void
    {
        $now = $this->clock->now();
        $this->store->pruneExpiredCodes($now - self::USED_CODE_KEPT_SECONDS, self::PRUNE_LIMIT);
        $this->store->pruneExpiredRefresh($now, self::PRUNE_LIMIT);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function exchangeCode(array $post): array
    {
        $code = self::param($post, 'code');
        $now = $this->clock->now();

        // The code is used up first, before anything else about the request
        // is judged, so that it is spent whether or not the exchange succeeds
        // (spec §5.3 step 8). A second presentation - even after the code has
        // expired - means it leaked or the client is replaying it; either way
        // nothing issued from it can be trusted (RFC 6749 §4.1.2).
        $row = null;
        $family = '';
        if ($code !== '') {
            $codeHash = hash('sha256', $code);
            $family = substr($codeHash, 0, 32);
            $row = $this->store->findCode($codeHash);
            if ($row !== null && !$this->store->consumeCode($codeHash, $now)) {
                $this->revokeFamily($family, $now);
                throw new OAuthError('invalid_grant', 'The code has already been used');
            }
        }

        $clientId = self::param($post, 'client_id');
        $redirectUri = self::param($post, 'redirect_uri');
        $verifier = self::param($post, 'code_verifier');
        if ($code === '' || $clientId === '' || $redirectUri === '' || $verifier === '') {
            throw new OAuthError('invalid_request', 'code, client_id, redirect_uri and code_verifier are required');
        }

        $client = $this->clients->resolveForToken($clientId);

        if ($row === null || (int)$row['expires_at'] <= $now) {
            throw new OAuthError('invalid_grant', 'The code is unknown or expired');
        }
        if ($row['client_id'] !== $clientId || $row['redirect_uri'] !== $redirectUri) {
            throw new OAuthError('invalid_grant', 'The code was issued to another client or redirect URI');
        }
        if (!Pkce::verify($verifier, (string)$row['code_challenge'])) {
            throw new OAuthError('invalid_grant', 'code_verifier does not match the code challenge');
        }
        $resource = self::param($post, 'resource');
        if ($resource !== '' && !self::sameResource($resource, $this->metadata->resource())) {
            throw new OAuthError('invalid_target', 'resource is not this server');
        }
        // RFC 8707: a resource named at both ends must be the same one. A
        // token request that omits it is allowed - some clients do - and the
        // code's own resource then stands.
        if (
            $resource !== '' && $row['resource'] !== null
            && !self::sameResource($resource, (string)$row['resource'])
        ) {
            throw new OAuthError('invalid_target', 'resource does not match the authorization request');
        }

        $company = (int)$row['company'];
        $login = (string)$row['login'];
        $scope = (string)$row['scope'];

        $issued = $this->issueAccessToken($client, $company, $login, $scope);
        [$refresh] = $this->saveRefresh($client, $company, $login, $scope, $family, $issued, $now);
        return $this->respond($client, $scope, $issued, $refresh);
    }

    /**
     * The refresh grant (spec §5.4). The new access token is issued and its
     * refresh row saved before anything is changed, so a failure in either
     * leaves the old refresh token as it was and the client can simply retry;
     * only then is the old refresh row rotated, which is the step that
     * decides a race.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function refresh(array $post): array
    {
        $presented = self::param($post, 'refresh_token');
        $clientId = self::param($post, 'client_id');
        if ($presented === '' || $clientId === '') {
            throw new OAuthError('invalid_request', 'refresh_token and client_id are required');
        }

        $client = $this->clients->resolveForToken($clientId);
        $now = $this->clock->now();

        $row = $this->store->findRefreshByHash(hash('sha256', $presented));
        if (
            $row === null || $row['client_id'] !== $clientId || $row['revoked_at'] !== null
            || (int)$row['expires_at'] <= $now
        ) {
            throw new OAuthError('invalid_grant', 'The refresh token is unknown, expired or revoked');
        }

        $family = (string)$row['family'];
        $company = (int)$row['company'];
        $login = (string)$row['login'];
        $oldJti = (string)$row['graphql_jti'];
        $scope = (string)$row['scope'];

        // A rotated token presented again: either the client or a thief holds
        // a copy, and there is no telling which, so the whole grant goes.
        if ($row['rotated_at'] !== null) {
            $this->revokeFamily($family, $now);
            throw new OAuthError('invalid_grant', 'The refresh token has already been used');
        }

        // Revoked with bin/fa-token: the user has ended this connection and
        // must sign in again. The access token having merely expired is the
        // normal case here and is not asked about.
        if ($this->accounts->tokenIsRevoked($company, $oldJti)) {
            $this->revokeFamily($family, $now);
            throw new OAuthError('invalid_grant', 'The connection has been revoked');
        }

        $issued = $this->issueAccessToken($client, $company, $login, $scope);
        [$refresh, $newId] = $this->saveRefresh($client, $company, $login, $scope, $family, $issued, $now);

        // Another request with the same refresh token won: the row and token
        // just made are surplus, and this request fails as a reuse would.
        if (!$this->store->rotateRefresh((int)$row['id'], $now)) {
            $this->store->deleteRefresh($newId);
            $this->accounts->revokeToken($company, (string)$issued['jti']);
            throw new OAuthError('invalid_grant', 'The refresh token has already been used');
        }

        $this->accounts->revokeToken($company, $oldJti);
        return $this->respond($client, $scope, $issued, $refresh);
    }

    /**
     * Issue the graphql access token. graphql refusing the user (inactive,
     * or no longer holding SA_GRAPHQL) is invalid_grant: the grant can no
     * longer be honoured. Any other failure that is not already an OAuth
     * error becomes server_error, with the cause kept off the response.
     *
     * @return array{token: string, jti: string, expiresAt: int}
     * @throws OAuthError
     */
    private function issueAccessToken(Client $client, int $company, string $login, string $scope): array
    {
        try {
            return $this->accounts->issueToken(
                $company,
                $login,
                $scope,
                $this->config->accessTokenTtl,
                self::tokenLabel($client)
            );
        } catch (OAuthError $e) {
            throw $e;
        } catch (AuthFailure $e) {
            throw new OAuthError('invalid_grant', 'The user is inactive or has no API access', 400, false, $e);
        } catch (\Exception $e) {
            throw new OAuthError('server_error', 'The access token could not be issued', 500, false, $e);
        }
    }

    /**
     * Store a new refresh token in $family for $issued. If that fails, the
     * access token just issued is revoked, since nothing could renew it and
     * the client is told to try again: server_error.
     *
     * @param array{token: string, jti: string, expiresAt: int} $issued
     * @return array{0: string, 1: int} the refresh token and its row's id
     * @throws OAuthError
     */
    private function saveRefresh(
        Client $client,
        int $company,
        string $login,
        string $scope,
        string $family,
        array $issued,
        int $now
    ): array {
        $refresh = self::base64Url(random_bytes(32));
        try {
            $id = $this->store->saveRefresh([
                'token_hash' => hash('sha256', $refresh),
                'family' => $family,
                'client_id' => $client->clientId(),
                'company' => $company,
                'login' => $login,
                'scope' => $scope,
                'graphql_jti' => (string)$issued['jti'],
                'expires_at' => $now + $this->config->refreshTokenTtl,
                'rotated_at' => null,
                'revoked_at' => null,
                'created_at' => $now,
            ]);
        } catch (\Exception $e) {
            $this->accounts->revokeToken($company, (string)$issued['jti']);
            throw new OAuthError('server_error', 'The refresh token could not be saved', 500, false, $e);
        }
        return [$refresh, $id];
    }

    /**
     * The token response, once everything is stored; the client is marked as used.
     *
     * @param array{token: string, jti: string, expiresAt: int} $issued
     * @return array<string, mixed>
     */
    private function respond(Client $client, string $scope, array $issued, string $refresh): array
    {
        $this->clients->touch($client->clientId());
        return [
            'access_token' => (string)$issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => max(0, (int)$issued['expiresAt'] - $this->clock->now()),
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }

    private function revokeFamily(string $family, int $now): void
    {
        foreach ($this->store->revokeFamily($family, $now) as $row) {
            $this->accounts->revokeToken((int)$row['company'], (string)$row['graphql_jti']);
        }
    }

    /** A requested scope string naming 'write' anywhere is 'write'; anything else is 'read'. */
    private static function normaliseScope(?string $requested): string
    {
        $words = preg_split('/\s+/', trim((string)$requested), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) && in_array('write', $words, true) ? 'write' : 'read';
    }

    /** "Claude (MCP) – <client name>", cut to 255 bytes on a character boundary. */
    private static function tokenLabel(Client $client): string
    {
        return mb_strcut(self::TOKEN_LABEL_PREFIX . $client->name(), 0, self::TOKEN_LABEL_MAX_BYTES, 'UTF-8');
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function sameResource(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }

    /**
     * A request parameter as a string; '' when absent or not a string.
     *
     * @param array<string, mixed> $params
     */
    private static function param(array $params, string $key): string
    {
        return isset($params[$key]) && is_string($params[$key]) ? $params[$key] : '';
    }
}
