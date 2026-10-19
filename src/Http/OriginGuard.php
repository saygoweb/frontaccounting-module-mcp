<?php

namespace FA\Mcp\Http;

use FA\Mcp\Config;

/**
 * The DNS-rebinding guard of spec §5.5: a request that carries an Origin must
 * come from base_url's origin or from one in allowed_origins.
 *
 * The module's own origin never comes from the request (a rebinding page
 * sends a Host that matches its own Origin): it is base_url's, and without
 * base_url only allowed_origins count. A request with no Origin is not a
 * browser's cross-origin request, so it is let through; Claude's servers and
 * the CLI send none. Origins are compared whole, ignoring case and a default
 * port only, so a '*' in allowed_origins matches nothing.
 */
final class OriginGuard
{
    public const FORBIDDEN_BODY = [
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32600, 'message' => 'Origin not allowed'],
    ];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** @var string[] normalised origins */
    private array $allowed = [];

    public function __construct(Config $config)
    {
        foreach ($config->allowedOrigins as $origin) {
            $normal = self::normalise($origin);
            if ($normal !== null) {
                $this->allowed[] = $normal;
            }
        }
        $own = self::normalise($config->baseUrl, true);
        if ($own !== null) {
            $this->allowed[] = $own;
        }
    }

    /** @param string|null $origin the Origin header; null or '' when absent */
    public function allows(?string $origin): bool
    {
        return $origin === null || $origin === '' || $this->isCorsOrigin($origin);
    }

    /** Whether the response should carry Access-Control-Allow-Origin for $origin. */
    public function isCorsOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return false;
        }
        $normal = self::normalise($origin);
        return $normal !== null && in_array($normal, $this->allowed, true);
    }

    /**
     * $url's origin, lower-cased: scheme://host, with the port only when it is
     * not the scheme's default. Null unless $url is an http(s) origin without
     * user info (or, with $dropPath, a URL whose path is ignored).
     */
    private static function normalise(string $url, bool $dropPath = false): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (!$dropPath && isset($parts['path']) && $parts['path'] !== '') {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            return null;
        }
        $origin = $scheme . '://' . strtolower($parts['host']);
        if (isset($parts['port']) && (int) $parts['port'] !== self::DEFAULT_PORTS[$scheme]) {
            $origin .= ':' . (int) $parts['port'];
        }
        return $origin;
    }
}
