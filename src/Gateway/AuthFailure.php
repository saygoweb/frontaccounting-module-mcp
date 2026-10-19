<?php

namespace FA\Mcp\Gateway;

/**
 * The GraphQL module refused the caller: graphql's ExecutorAuthException,
 * translated at the gateway so nothing past it depends on graphql's classes.
 */
final class AuthFailure extends \RuntimeException
{
    /** No, invalid, expired or revoked token: HTTP 401 with a Bearer challenge. */
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';
    /** A valid token whose user lacks SA_GRAPHQL (or, for a read token, a mutation): HTTP 403. */
    public const FORBIDDEN = 'FORBIDDEN';

    private string $reason;

    /** @param string $reason self::UNAUTHENTICATED or self::FORBIDDEN */
    public function __construct(string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
    }

    /** self::UNAUTHENTICATED or self::FORBIDDEN. */
    public function reason(): string
    {
        return $this->reason;
    }
}
