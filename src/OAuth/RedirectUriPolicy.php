<?php

namespace FA\Mcp\OAuth;

/**
 * Which redirect URIs a client may register, and when a requested one matches.
 *
 * HTTPS everywhere except loopback, which Claude CLI uses: it listens on
 * whatever port it is given, so a loopback http URI matches a registered one
 * ignoring the port (RFC 8252 §7.3). Everything else is an exact string match.
 */
final class RedirectUriPolicy
{
    public const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** Registration-time check: https, or http loopback (localhost, 127.0.0.1, [::1]); no fragment. */
    public static function isAcceptable(string $uri): bool
    {
        $parts = self::parse($uri);
        if ($parts === null) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        return $parts['scheme'] === 'http' && self::isLoopback($parts);
    }

    /**
     * Exact match, except loopback http URIs match ignoring port.
     *
     * @param string[] $registered
     */
    public static function matches(string $requested, array $registered): bool
    {
        foreach ($registered as $candidate) {
            if ($requested === (string)$candidate) {
                return true;
            }
        }

        $req = self::parse($requested);
        if ($req === null || $req['scheme'] !== 'http' || !self::isLoopback($req)) {
            return false;
        }
        foreach ($registered as $candidate) {
            $reg = self::parse((string)$candidate);
            if (
                $reg !== null && $reg['scheme'] === 'http' && self::isLoopback($reg)
                && self::withoutPort($req) === self::withoutPort($reg)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The parts of an absolute URI with a host, no userinfo and no fragment,
     * or null. Scheme and host are lowercased, as both are case-insensitive.
     *
     * @return array<string, string|int>|null
     */
    private static function parse(string $uri): ?array
    {
        if ($uri === '' || strpos($uri, '#') !== false || preg_match('/[\x00-\x20\x7f]/', $uri)) {
            return null;
        }
        $parts = parse_url($uri);
        if (
            $parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return null;
        }
        $parts['scheme'] = strtolower($parts['scheme']);
        $parts['host'] = strtolower($parts['host']);
        return $parts;
    }

    private static function isLoopback(array $parts): bool
    {
        return in_array($parts['host'], self::LOOPBACK_HOSTS, true);
    }

    private static function withoutPort(array $parts): string
    {
        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['path']) ? $parts['path'] : '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
