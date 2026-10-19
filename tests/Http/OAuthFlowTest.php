<?php

namespace FA\Mcp\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * The OAuth 2.1 flow through Apache, as Claude.ai or Claude Code without a
 * header would go through it (spec §5.3, §5.4): discovery, DCR, the login and
 * consent pages with a cookie jar, the code and refresh grants, and an
 * administrator revoking the connection with bin/fa-token.
 */
class OAuthFlowTest extends TestCase
{
    use McpClient;

    private const CLIENT_NAME = 'Test 🧪';
    private const LABEL = 'Claude (MCP) – Test 🧪';
    private const REDIRECT = 'http://127.0.0.1:33418/callback';
    private const ROOT_RULE_MARKER = '# fa-mcp: RFC 8414 metadata at the origin root';

    /** @var string|null the client registered once for the class */
    private static ?string $clientId = null;

    private string $cookieJar = '';

    protected function setUp(): void
    {
        $modules = dirname(__DIR__, 3);
        if (!is_file($modules . '/graphql/bin/fa-token') || !is_file(dirname($modules) . '/config_db.php')) {
            $this->markTestSkipped('Needs the FrontAccounting CI image with the GraphQL module beside this one.');
        }
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'fa-mcp-jar');
    }

    protected function tearDown(): void
    {
        if ($this->cookieJar !== '' && is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Each connection's current token is a graphql_machine_token row; end those left live.
        foreach (self::liveConnectionJtis() as $jti) {
            self::revokeToken($jti);
        }
    }

    private static function baseUrl(): string
    {
        return rtrim(self::mcpUrl(), '/');
    }

    private static function origin(): string
    {
        $parts = parse_url(self::baseUrl());
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    // Step 1-2: discovery.
    public function testDiscoveryFromTheUnauthorizedResponse(): void
    {
        $response = $this->rpc('tools/list');
        $this->assertSame(401, $response['status'], $response['raw']);
        $challenge = $response['headers']['www-authenticate'] ?? '';
        $this->assertSame(1, preg_match('/resource_metadata="([^"]+)"/', $challenge, $m), $challenge);
        $this->assertSame(self::baseUrl() . '/.well-known/oauth-protected-resource', $m[1]);

        $resource = $this->httpUrl('GET', $m[1]);
        $this->assertSame(200, $resource['status'], $resource['raw']);
        $this->assertSame(self::baseUrl(), $resource['body']['authorization_servers'][0]);
        $this->assertSame(self::baseUrl() . '/', $resource['body']['resource']);

        $server = $this->httpUrl('GET', self::baseUrl() . '/.well-known/oauth-authorization-server');
        $openid = $this->httpUrl('GET', self::baseUrl() . '/.well-known/openid-configuration');
        $this->assertSame(200, $server['status'], $server['raw']);
        $this->assertSame($server['raw'], $openid['raw']);
        $this->assertSame(self::baseUrl(), $server['body']['issuer']);
        $this->assertSame(['S256'], $server['body']['code_challenge_methods_supported']);
        $this->assertSame(self::baseUrl() . '/oauth/register', $server['body']['registration_endpoint']);
    }

    // Step 3: spec §5.1's optional rule for FrontAccounting's root .htaccess.
    public function testTheRootRewriteServesTheIssuerMetadata(): void
    {
        $htaccess = dirname(__DIR__, 4) . '/.htaccess';
        if (!is_writable($htaccess)) {
            $this->markTestSkipped('FA root .htaccess not writable');
        }
        $current = (string) file_get_contents($htaccess);
        if (strpos($current, self::ROOT_RULE_MARKER) === false) {
            file_put_contents($htaccess, rtrim($current) . "\n\n" . self::ROOT_RULE_MARKER . " (modules/mcp)\n"
                . "RewriteEngine On\n"
                . 'RewriteRule ^\.well-known/(oauth-authorization-server|openid-configuration|oauth-protected-resource)'
                . "/modules/mcp/?$ modules/mcp/.well-known/$1 [L]\n");
        }

        $path = (string) parse_url(self::baseUrl(), PHP_URL_PATH);
        $expected = $this->httpUrl('GET', self::baseUrl() . '/.well-known/oauth-authorization-server');
        $root = $this->httpUrl('GET', self::origin() . '/.well-known/oauth-authorization-server' . $path);
        $this->assertSame(200, $root['status'], $root['raw']);
        $this->assertSame($expected['raw'], $root['raw']);

        $resource = $this->httpUrl('GET', self::origin() . '/.well-known/oauth-protected-resource' . $path . '/');
        $this->assertSame(200, $resource['status'], $resource['raw']);
        $this->assertSame(self::baseUrl() . '/', $resource['body']['resource']);
    }

    // Step 4: DCR.
    public function testRegistrationKeepsTheEmoji(): void
    {
        $response = $this->register();
        $this->assertSame(201, $response['status'], $response['raw']);
        $this->assertSame(self::CLIENT_NAME, $response['body']['client_name']);
        $this->assertSame([self::REDIRECT], $response['body']['redirect_uris']);
    }

    // Steps 5-8.
    public function testTheCodeFlowRefreshAndReuseDetection(): void
    {
        $first = $this->connect('write');
        $this->assertSame(3600, $first['expires_in']);
        $this->assertSame('write', $first['scope']);
        $this->assertSame('Bearer', $first['token_type']);
        $this->assertContains('customer_create', $this->toolNames($first['access_token']));

        $second = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']]);
        $this->assertSame(200, $second['status'], $second['raw']);
        $this->assertNotSame($first['access_token'], $second['body']['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['body']['refresh_token']);
        $this->assertContains('customer_create', $this->toolNames($second['body']['access_token']));

        $old = $this->rpc('tools/list', [], $first['access_token']);
        $this->assertSame(401, $old['status'], 'the rotated-out access token is revoked: ' . $old['raw']);

        $replay = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $first['refresh_token']]);
        $this->assertSame(400, $replay['status'], $replay['raw']);
        $this->assertSame('invalid_grant', $replay['body']['error']);

        $after = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $second['body']['refresh_token']]);
        $this->assertSame('invalid_grant', $after['body']['error'] ?? null, 'the family is revoked: ' . $after['raw']);
        $this->assertSame(401, $this->rpc('tools/list', [], $second['body']['access_token'])['status']);
    }

    // Steps 9-10.
    public function testAReadConnectionAndRevocationByTheAdministrator(): void
    {
        $read = $this->connect('read');
        $this->assertSame('read', $read['scope']);
        $names = $this->toolNames($read['access_token']);
        $this->assertContains('customer_list', $names);
        $this->assertNotContains('customer_create', $names);

        $live = self::liveConnectionJtis();
        $this->assertNotEmpty($live, 'fa-token list shows the connection labelled ' . self::LABEL);
        foreach ($live as $jti) {
            $this->assertTrue(self::revokeToken($jti), "fa-token revoke $jti");
        }

        $this->assertSame(401, $this->rpc('tools/list', [], $read['access_token'])['status']);
        $refresh = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $read['refresh_token']]);
        $this->assertSame(400, $refresh['status'], $refresh['raw']);
        $this->assertSame('invalid_grant', $refresh['body']['error']);
    }

    public function testCsrfIsCheckedOverHttp(): void
    {
        $pkce = self::pkce();
        $url = $this->authorizeUrl('read', $pkce['challenge'], 'csrf');
        $page = $this->httpUrl('GET', $url, [], null, $this->cookieJar);
        $this->assertSame(200, $page['status'], $page['raw']);
        $login = $this->form('POST', $url, [
            'action' => 'login', 'company' => '0', 'user' => self::ciUser(), 'password' => self::ciPassword(),
            'csrf' => str_repeat('0', 64),
        ]);
        $this->assertSame(400, $login['status'], $login['raw']);
    }

    public function testBadCredentialsShowTheFormAgain(): void
    {
        $pkce = self::pkce();
        $url = $this->authorizeUrl('read', $pkce['challenge'], 'bad');
        $page = $this->httpUrl('GET', $url, [], null, $this->cookieJar);
        $login = $this->form('POST', $url, [
            'action' => 'login', 'company' => '0', 'user' => self::ciUser(), 'password' => 'not-the-password',
            'csrf' => self::csrfOf($page['raw']),
        ]);
        $this->assertSame(200, $login['status'], $login['raw']);
        $this->assertStringContainsString('The user name or password is incorrect.', $login['raw']);
    }

    /** @return array{status: int, headers: array<string, string>, body: array|null, raw: string} */
    private function register(): array
    {
        $body = (string) json_encode([
            'client_name' => self::CLIENT_NAME,
            'redirect_uris' => [self::REDIRECT],
        ], JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json'];
        return $this->httpUrl('POST', self::baseUrl() . '/oauth/register', $headers, $body);
    }

    private function clientId(): string
    {
        if (self::$clientId === null) {
            $response = $this->register();
            $this->assertSame(201, $response['status'], $response['raw']);
            self::$clientId = (string) $response['body']['client_id'];
        }
        return self::$clientId;
    }

    /** @return array{verifier: string, challenge: string} */
    private static function pkce(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    private function authorizeUrl(string $scope, string $challenge, string $state): string
    {
        return self::baseUrl() . '/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId(),
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'scope' => $scope,
            'state' => $state,
            'resource' => self::baseUrl() . '/',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * A form submission through the cookie jar, as the page's own form would post it.
     *
     * @param array<string, string> $fields
     * @return array{status: int, headers: array<string, string>, body: array|null, raw: string}
     */
    private function form(string $method, string $url, array $fields): array
    {
        return $this->httpUrl(
            $method,
            $url,
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields),
            $this->cookieJar
        );
    }

    private static function csrfOf(string $html): string
    {
        return preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) === 1 ? $m[1] : '';
    }

    /**
     * Steps 5-6: the browser part (login, consent), then the code exchange.
     *
     * @return array<string, mixed> the token response
     */
    private function connect(string $scope): array
    {
        $pkce = self::pkce();
        $state = bin2hex(random_bytes(8));
        $url = $this->authorizeUrl($scope, $pkce['challenge'], $state);

        $page = $this->httpUrl('GET', $url, [], null, $this->cookieJar);
        $this->assertSame(200, $page['status'], $page['raw']);
        $this->assertStringContainsString('name="password"', $page['raw']);
        $this->assertStringContainsString('fa_mcp_oauth', (string) file_get_contents($this->cookieJar));

        $consent = $this->form('POST', $url, [
            'action' => 'login', 'company' => '0', 'user' => self::ciUser(), 'password' => self::ciPassword(),
            'csrf' => self::csrfOf($page['raw']),
        ]);
        $this->assertSame(200, $consent['status'], $consent['raw']);
        $text = (string) preg_replace('/\s+/', ' ', strip_tags($consent['raw']));
        $this->assertStringContainsString(
            self::CLIENT_NAME . ' wants to use FrontAccounting as ' . self::ciUser() . ' in ',
            $text
        );
        $this->assertSame("frame-ancestors 'none'", substr($consent['headers']['content-security-policy'], -22));
        $this->assertSame('DENY', $consent['headers']['x-frame-options']);

        $allowed = $this->form('POST', $url, [
            'action' => 'allow', 'granted_scope' => $scope, 'csrf' => self::csrfOf($consent['raw']),
        ]);
        $this->assertSame(302, $allowed['status'], $allowed['raw']);
        $location = $allowed['headers']['location'] ?? '';
        $this->assertStringStartsWith(self::REDIRECT . '?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->assertSame($state, $params['state']);
        $this->assertSame(self::baseUrl(), $params['iss']);
        $this->assertSame(64, strlen($params['code']));

        $token = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $params['code'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $pkce['verifier'],
            'resource' => self::baseUrl() . '/',
        ]);
        $this->assertSame(200, $token['status'], $token['raw']);
        $this->assertNotEmpty($token['body']['access_token']);
        $this->assertNotEmpty($token['body']['refresh_token']);
        return $token['body'];
    }

    /**
     * @param array<string, string> $fields client_id is added
     * @return array{status: int, headers: array<string, string>, body: array|null, raw: string}
     */
    private function token(array $fields): array
    {
        return $this->httpUrl(
            'POST',
            self::baseUrl() . '/oauth/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields + ['client_id' => $this->clientId()])
        );
    }

    /** @return string[] */
    private function toolNames(string $token): array
    {
        $response = $this->rpc('tools/list', [], $token);
        $this->assertSame(200, $response['status'], $response['raw']);
        return array_column($response['body']['result']['tools'], 'name');
    }

    /** @return string[] the jtis fa-token lists as live with this test's connection label */
    private static function liveConnectionJtis(): array
    {
        [$status, $out] = self::faToken(['list', '--company', '0']);
        if ($status !== 0) {
            return [];
        }
        $jtis = [];
        foreach (explode("\n", $out) as $line) {
            $columns = explode("\t", $line);
            if (count($columns) === 7 && $columns[2] === 'live' && $columns[6] === self::LABEL) {
                $jtis[] = $columns[0];
            }
        }
        return $jtis;
    }
}
