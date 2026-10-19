<?php

namespace FA\Mcp\Gateway;

use FA\GraphQL\Api\Executor;
use FA\GraphQL\Api\ExecutorAuthException;

/**
 * The production AccountGateway: graphql's in-process Executor (contract 1.x).
 * The end-client address is given once, for the request, and passed to the
 * calls that record or throttle by it (authenticate, issueToken).
 *
 * ExecutorAuthException becomes AuthFailure with the same reason. revokeToken
 * and tokenIsRevoked answer for a company that may no longer open, so any
 * other failure of theirs is an AuthFailure UNAUTHENTICATED too: the refresh
 * flow then refuses the grant instead of answering server_error.
 */
final class ExecutorAccountGateway implements AccountGateway
{
    private Executor $executor;
    private string $clientIp;

    public function __construct(Executor $executor, string $clientIp = '')
    {
        $this->executor = $executor;
        $this->clientIp = $clientIp;
    }

    public function companies(): array
    {
        return $this->executor->companies();
    }

    public function authenticate(int $company, string $login, string $password): array
    {
        try {
            return $this->executor->authenticate($company, $login, $password, $this->clientIp);
        } catch (ExecutorAuthException $e) {
            throw new AuthFailure($e->getReason(), $e->getMessage(), $e);
        }
    }

    public function issueToken(int $company, string $login, string $scope, int $ttlSeconds, string $label): array
    {
        try {
            return $this->executor->issueToken($company, $login, $scope, $ttlSeconds, $label, $this->clientIp);
        } catch (ExecutorAuthException $e) {
            throw new AuthFailure($e->getReason(), $e->getMessage(), $e);
        }
    }

    public function revokeToken(int $company, string $jti): bool
    {
        try {
            return $this->executor->revokeToken($company, $jti);
        } catch (ExecutorAuthException $e) {
            throw new AuthFailure($e->getReason(), $e->getMessage(), $e);
        } catch (\Throwable $e) {
            throw new AuthFailure(AuthFailure::UNAUTHENTICATED, $e->getMessage(), $e);
        }
    }

    public function tokenIsRevoked(int $company, string $jti): bool
    {
        try {
            return $this->executor->tokenIsRevoked($company, $jti);
        } catch (ExecutorAuthException $e) {
            throw new AuthFailure($e->getReason(), $e->getMessage(), $e);
        } catch (\Throwable $e) {
            throw new AuthFailure(AuthFailure::UNAUTHENTICATED, $e->getMessage(), $e);
        }
    }
}
