<?php

namespace FA\Mcp\Tests\Support;

use FA\Mcp\Gateway\AccountGateway;
use FA\Mcp\Gateway\AuthFailure;

/**
 * FrontAccounting users and graphql tokens held in arrays. A test reads
 * $issued, $revoked and liveJtis() to see what the OAuth server asked for,
 * and calls setActive(), setApiAccess() or revokeByUser() to play FA and
 * graphql changing underneath it.
 */
final class FakeAccountGateway implements AccountGateway
{
    /** @var array<int, string> company id => name */
    public array $companies = [0 => 'Demo', 1 => 'Training', 2 => 'Branch'];

    /** @var array<int, array<string, array{password: string, active: bool, apiAccess: bool}>> [company][login] */
    public array $users = [];

    /** @var array<string, array{company: int, login: string}> jti => owner, every token ever issued */
    private array $owner = [];

    /** @var array<string, true> jti => true once revoked, by anyone */
    private array $revokedJtis = [];

    /**
     * @var callable|null run once at the start of the next issueToken() call,
     *                    then cleared: it can throw to play a failed mint, or
     *                    act as a concurrent request
     */
    public $beforeIssue;

    /** @var FixedClock|null issued tokens expire $ttlSeconds after its now(), else after 1700000000 */
    public ?FixedClock $clock = null;

    /** @var int|null when set, the expiresAt every issued token reports, whatever the TTL */
    public ?int $expiresAt = null;

    /** @var array[] each ['company', 'login', 'scope', 'ttl', 'label', 'jti', 'token'], in call order */
    public array $issued = [];

    /** @var array[] each ['company' => int, 'jti' => string], revokeToken() calls in order */
    public array $revoked = [];

    /** @var array[] each ['company' => int, 'jti' => string], tokenIsRevoked() calls in order */
    public array $revocationChecks = [];

    private int $nextJti = 100;

    public function addUser(
        int $company,
        string $login,
        string $password = 'secret',
        bool $active = true,
        bool $apiAccess = true
    ): void {
        $this->users[$company][$login] = ['password' => $password, 'active' => $active, 'apiAccess' => $apiAccess];
    }

    public function setActive(int $company, string $login, bool $active): void
    {
        $this->users[$company][$login]['active'] = $active;
    }

    public function setApiAccess(int $company, string $login, bool $apiAccess): void
    {
        $this->users[$company][$login]['apiAccess'] = $apiAccess;
    }

    /** The user revokes the token outside the OAuth server (bin/fa-token revoke). */
    public function revokeByUser(string $jti): void
    {
        $this->revokedJtis[$jti] = true;
    }

    /** @return string[] the jtis passed to revokeToken(), in call order */
    public function revokedIds(): array
    {
        return array_column($this->revoked, 'jti');
    }

    /** @return string[] the jtis issued and not revoked, in issue order */
    public function liveJtis(): array
    {
        $live = [];
        foreach ($this->issued as $issued) {
            if (!isset($this->revokedJtis[$issued['jti']])) {
                $live[] = $issued['jti'];
            }
        }
        return $live;
    }

    public function companies(): array
    {
        return $this->companies;
    }

    public function authenticate(int $company, string $login, string $password): array
    {
        $user = $this->users[$company][$login] ?? null;
        if ($user === null || !$user['active'] || $user['password'] !== $password) {
            return ['status' => 'BAD_CREDENTIALS', 'login' => $login];
        }
        return ['status' => $user['apiAccess'] ? 'OK' : 'NO_API_ACCESS', 'login' => $login];
    }

    public function issueToken(int $company, string $login, string $scope, int $ttlSeconds, string $label): array
    {
        if ($this->beforeIssue !== null) {
            $hook = $this->beforeIssue;
            $this->beforeIssue = null;
            $hook();
        }
        $user = $this->users[$company][$login] ?? null;
        if ($user === null || !$user['active']) {
            throw new AuthFailure(AuthFailure::UNAUTHENTICATED, 'The user is unknown or inactive.');
        }
        if (!$user['apiAccess']) {
            throw new AuthFailure(AuthFailure::FORBIDDEN, 'The user has no GraphQL API access.');
        }

        $jti = str_pad(dechex($this->nextJti++), 32, '0', STR_PAD_LEFT);
        $token = 'fa_fake_' . $jti;
        $this->owner[$jti] = ['company' => $company, 'login' => $login];
        $this->issued[] = [
            'company' => $company, 'login' => $login, 'scope' => $scope, 'ttl' => $ttlSeconds,
            'label' => $label, 'jti' => $jti, 'token' => $token,
        ];
        $expiresAt = $this->expiresAt ?? ($this->clock !== null ? $this->clock->now() : 1700000000) + $ttlSeconds;
        return ['token' => $token, 'jti' => $jti, 'expiresAt' => $expiresAt];
    }

    public function revokeToken(int $company, string $jti): bool
    {
        $this->revoked[] = ['company' => $company, 'jti' => $jti];
        $wasLive = isset($this->owner[$jti]) && $this->owner[$jti]['company'] === $company
            && !isset($this->revokedJtis[$jti]);
        if ($wasLive) {
            $this->revokedJtis[$jti] = true;
        }
        return $wasLive;
    }

    public function tokenIsRevoked(int $company, string $jti): bool
    {
        $this->revocationChecks[] = ['company' => $company, 'jti' => $jti];
        return !isset($this->owner[$jti]) || $this->owner[$jti]['company'] !== $company
            || isset($this->revokedJtis[$jti]);
    }
}
