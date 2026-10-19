<?php

namespace FA\Mcp\OAuth;

/** RFC 7636 proof key, S256 only: "plain" proves nothing to a server that stores the challenge. */
final class Pkce
{
    public const PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

    public static function verify(string $verifier, string $challenge): bool
    {
        if (!preg_match(self::PATTERN, $verifier)) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }

    /** A challenge is a base64url SHA-256, but the RFC only bounds it as a verifier is bounded. */
    public static function isWellFormedChallenge(string $challenge): bool
    {
        return (bool)preg_match(self::PATTERN, $challenge);
    }
}
