<?php

namespace FA\Mcp\OAuth;

/**
 * Fetches and checks a Client ID Metadata Document (spec §7).
 *
 * The client_id is a URL the client chose, so fetching it is a server-side
 * request to an address an anonymous caller picked. The guard against that
 * reaching the server's own network: resolve the host once, refuse if any
 * address is not public, and connect to the first address only, so DNS cannot
 * answer differently between the check and the connection.
 */
final class CimdFetcher
{
    public const TIMEOUT_SECONDS = 5;
    public const MAX_BYTES = 65536;

    /**
     * Never fetched: private, shared, loopback, link-local, documentation,
     * benchmarking, reserved, broadcast and multicast space. On the IPv6 side
     * ::/96 (unspecified, loopback and IPv4-compatible) and ::ffff:0:0/96
     * (every IPv4-mapped address, so the mapped form of each IPv4 range above
     * is blocked too), the discard prefix, documentation, the NAT64 and 6to4
     * prefixes (each can carry a private IPv4 address inside an IPv6 one),
     * ULA, link-local and multicast.
     */
    public const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255',
        '::/96', '::ffff:0:0/96', '100::/64', '2001:db8::/32', '64:ff9b::/96', '2002::/16',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    private HttpFetcher $http;
    /** @var callable */
    private $resolve;

    /**
     * @param callable|null $resolve function (string $host): string[], the
     *                               host's addresses; DNS A and AAAA when null
     */
    public function __construct(HttpFetcher $http, ?callable $resolve = null)
    {
        $this->http = $http;
        $this->resolve = $resolve !== null ? $resolve : [self::class, 'dnsAddresses'];
    }

    /** @throws OAuthError invalid_client on any failure */
    public function fetch(string $url): Client
    {
        $host = self::hostOf($url);
        if ($host === null) {
            throw self::fail('The client_id must be an https URL with a path, on port 443, without credentials.');
        }

        if (@inet_pton($host) !== false) {
            $addresses = [$host];
        } else {
            $addresses = array_values(array_map('strval', (array)call_user_func($this->resolve, $host)));
        }
        if (!$addresses) {
            throw self::fail('The client_id host does not resolve.');
        }
        foreach ($addresses as $address) {
            if (!self::isPublic($address)) {
                throw self::fail('The client_id host resolves to an address that is not public.');
            }
        }

        try {
            $response = $this->http->get($url, $addresses[0], self::TIMEOUT_SECONDS, self::MAX_BYTES);
        } catch (\RuntimeException $e) {
            throw self::fail('The client metadata document could not be fetched.');
        }
        if ((int)$response['status'] !== 200) {
            throw self::fail(sprintf('The client metadata document returned HTTP %d.', (int)$response['status']));
        }

        $doc = json_decode((string)$response['body'], true);
        if (!is_array($doc)) {
            throw self::fail('The client metadata document is not a JSON object.');
        }
        if (!isset($doc['client_id']) || $doc['client_id'] !== $url) {
            throw self::fail('The client metadata document\'s client_id does not match its URL.');
        }
        $uris = isset($doc['redirect_uris']) ? $doc['redirect_uris'] : null;
        if (!is_array($uris) || !$uris) {
            throw self::fail('The client metadata document has no redirect_uris.');
        }
        // The limits DCR applies (ClientRegistry::register()): what is stored
        // must fit its columns, whoever wrote the document.
        if (count($uris) > ClientRegistry::MAX_REDIRECT_URIS) {
            throw self::fail('The client metadata document has more than '
                . ClientRegistry::MAX_REDIRECT_URIS . ' redirect URIs.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || strlen($uri) > ClientRegistry::MAX_REDIRECT_URI_BYTES) {
                throw self::fail('Each redirect URI in the client metadata document must be a string of at most '
                    . ClientRegistry::MAX_REDIRECT_URI_BYTES . ' bytes.');
            }
            if (!RedirectUriPolicy::isAcceptable($uri)) {
                throw self::fail('The client metadata document has an unacceptable redirect URI.');
            }
        }

        $name = isset($doc['client_name']) && is_string($doc['client_name']) ? trim($doc['client_name']) : '';
        if (strlen($name) > ClientRegistry::MAX_CLIENT_NAME_BYTES) {
            throw self::fail('The client metadata document\'s client_name is longer than '
                . ClientRegistry::MAX_CLIENT_NAME_BYTES . ' bytes.');
        }
        if ($name === '') {
            $name = $host;
        }

        return new Client($url, 'cimd', $name, $uris);
    }

    /** Whether $ip is a well-formed address outside every blocked range. */
    public static function isPublic(string $ip): bool
    {
        return @inet_pton($ip) !== false && !self::ipInCidrs($ip, self::BLOCKED);
    }

    /**
     * Whether $ip lies in any of $cidrs ("addr/bits", or a bare address for
     * one host). Malformed entries, and those of the other address family,
     * never match.
     *
     * @param string[] $cidrs
     */
    private static function ipInCidrs(string $ip, array $cidrs): bool
    {
        $addr = @inet_pton($ip);
        if ($addr === false) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            $parts = explode('/', trim((string)$cidr), 2);
            $net = @inet_pton($parts[0]);
            if ($net === false || strlen($net) !== strlen($addr)) {
                continue;
            }
            $max = strlen($net) * 8;
            if (isset($parts[1])) {
                if (!ctype_digit($parts[1])) {
                    continue;
                }
                $bits = (int)$parts[1];
                if ($bits > $max) {
                    continue;
                }
            } else {
                $bits = $max;
            }
            $bytes = intdiv($bits, 8);
            if ($bytes > 0 && substr($addr, 0, $bytes) !== substr($net, 0, $bytes)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($addr[$bytes]) & $mask) === (ord($net[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The lowercased host of an acceptable CIMD URL (brackets stripped from an
     * IPv6 literal), or null: https, port 443 or none, no userinfo, no
     * fragment, and a path beyond "/".
     */
    public static function hostOf(string $url): ?string
    {
        if (strpos($url, '#') !== false || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (
            $parts === false || !isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
            || !isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/'
        ) {
            return null;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        return $host === '' ? null : $host;
    }

    /** @return string[] the host's A and AAAA records */
    public static function dnsAddresses(string $host): array
    {
        $addresses = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = (string)$record['ipv6'];
                }
            }
        }
        return $addresses;
    }

    private static function fail(string $description): OAuthError
    {
        return new OAuthError('invalid_client', $description, 401);
    }
}
