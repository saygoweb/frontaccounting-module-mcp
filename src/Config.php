<?php

namespace FA\Mcp;

/**
 * config_mcp.php, validated (spec §8). Every key has a default, so a missing
 * file is fine: OAuth is then off (no base_url) and bearer-only use works out
 * of the box. The properties are read-only by convention.
 */
final class Config
{
    private const DEFAULTS = [
        'base_url' => '',
        'oauth' => true,
        'require_tls' => true,
        'allowed_origins' => ['https://claude.ai'],
        'access_token_ttl' => 3600,
        'refresh_token_ttl' => 2592000,
        'default_list_limit' => 50,
        'escape_hatch' => true,
        'allow_dcr' => true,
        'allow_cimd' => true,
        'cimd_cache_ttl' => 86400,
        'dcr_rate_limit' => 10,
        'debug' => false,
    ];

    private const POSITIVE_INTS = [
        'access_token_ttl',
        'refresh_token_ttl',
        'cimd_cache_ttl',
        'dcr_rate_limit',
    ];

    private const BOOLS = ['oauth', 'require_tls', 'escape_hatch', 'allow_dcr', 'allow_cimd', 'debug'];

    private const MAX_LIST_LIMIT = 1000;

    /** Canonical public URL of the module, no trailing slash; '' when unset. Required for OAuth. */
    public string $baseUrl;
    /** Serve the OAuth endpoints and metadata (only when baseUrl is set; see oauthEnabled()). */
    public bool $oauth;
    /** Refuse plain HTTP (graphql's trust_proxy decides forwarded HTTPS). */
    public bool $requireTls;
    /** @var string[] Origins accepted besides baseUrl's own. */
    public array $allowedOrigins;
    /** Seconds. */
    public int $accessTokenTtl;
    /** Seconds (30 days by default). */
    public int $refreshTokenTtl;
    /** The query.limit injected into list tools, 1..1000. */
    public int $defaultListLimit;
    /** List the graphql_* escape-hatch tools. */
    public bool $escapeHatch;
    /** Offer RFC 7591 dynamic client registration. */
    public bool $allowDcr;
    /** Accept Client ID Metadata Documents. */
    public bool $allowCimd;
    /** Seconds a fetched CIMD document is cached. */
    public int $cimdCacheTtl;
    /** Registrations (and new CIMD lookups) per source IP per hour. */
    public int $dcrRateLimit;
    /** Exception messages in error responses. */
    public bool $debug;

    private function __construct()
    {
    }

    /** The file returns an array (`return [ ... ];`); a missing file means every default. */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return self::fromArray([]);
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new ConfigException(basename($path) . ' must return an array.');
        }

        return self::fromArray($values);
    }

    /**
     * @param array<string, mixed> $values
     * @throws ConfigException for an unknown key or a value of the wrong type
     */
    public static function fromArray(array $values): self
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));
        if ($unknown) {
            throw new ConfigException('Unknown configuration key: ' . implode(', ', $unknown));
        }
        $v = $values + self::DEFAULTS;

        if (!is_string($v['base_url'])) {
            throw new ConfigException('Configuration key base_url must be a string.');
        }
        if ($v['base_url'] !== '' && !self::isBaseUrl($v['base_url'])) {
            throw new ConfigException(
                'Configuration key base_url must be an absolute http(s):// URL'
                . ' with no trailing slash, query or fragment.'
            );
        }
        foreach (self::POSITIVE_INTS as $key) {
            if (!is_int($v[$key]) || $v[$key] < 1) {
                throw new ConfigException("Configuration key $key must be a positive integer.");
            }
        }
        $limit = $v['default_list_limit'];
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_LIST_LIMIT) {
            throw new ConfigException(
                'Configuration key default_list_limit must be an integer from 1 to ' . self::MAX_LIST_LIMIT . '.'
            );
        }
        foreach (self::BOOLS as $key) {
            if (!is_bool($v[$key])) {
                throw new ConfigException("Configuration key $key must be a boolean.");
            }
        }
        $origins = $v['allowed_origins'];
        if (!is_array($origins) || array_values($origins) !== $origins) {
            throw new ConfigException('Configuration key allowed_origins must be a list of strings.');
        }
        foreach ($origins as $origin) {
            if (!is_string($origin)) {
                throw new ConfigException('Configuration key allowed_origins must be a list of strings.');
            }
        }

        $config = new self();
        $config->baseUrl = $v['base_url'];
        $config->oauth = $v['oauth'];
        $config->requireTls = $v['require_tls'];
        $config->allowedOrigins = $origins;
        $config->accessTokenTtl = $v['access_token_ttl'];
        $config->refreshTokenTtl = $v['refresh_token_ttl'];
        $config->defaultListLimit = $limit;
        $config->escapeHatch = $v['escape_hatch'];
        $config->allowDcr = $v['allow_dcr'];
        $config->allowCimd = $v['allow_cimd'];
        $config->cimdCacheTtl = $v['cimd_cache_ttl'];
        $config->dcrRateLimit = $v['dcr_rate_limit'];
        $config->debug = $v['debug'];

        return $config;
    }

    /** OAuth is served only when it is on and the module knows its own public URL. */
    public function oauthEnabled(): bool
    {
        return $this->oauth && $this->baseUrl !== '';
    }

    private static function isBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && $parts['host'] !== ''
            // A bare "?" or "#" leaves no query or fragment part, so look at the string.
            && strpos($url, '?') === false
            && strpos($url, '#') === false
            && substr($url, -1) !== '/';
    }
}
