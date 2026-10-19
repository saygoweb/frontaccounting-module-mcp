<?php

namespace FA\Mcp\Tests\Unit\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\OAuth\AuthorizationServer;
use FA\Mcp\OAuth\CimdFetcher;
use FA\Mcp\OAuth\Client;
use FA\Mcp\OAuth\ClientRegistry;
use FA\Mcp\OAuth\Http\AuthorizeAction;
use FA\Mcp\OAuth\Http\McpSession;
use FA\Mcp\OAuth\Http\Pages;
use FA\Mcp\OAuth\InMemoryStore;
use FA\Mcp\OAuth\Metadata;
use FA\Mcp\Tests\Support\FakeAccountGateway;
use FA\Mcp\Tests\Support\FakeHttpFetcher;
use FA\Mcp\Tests\Support\FixedClock;
use FA\Mcp\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class AuthorizeActionTest extends TestCase
{
    private const BASE = 'https://fa.example.com/modules/mcp';
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const PATH = '/modules/mcp/oauth/authorize';
    private const SECRET = 'the-graphql-secret-0123456789abcdef';

    private FixedClock $clock;
    private InMemoryStore $store;
    private FakeAccountGateway $accounts;
    private ClientRegistry $clients;
    private Client $client;
    private Config $config;
    private AuthorizationServer $server;
    /** @var string|null the fa_mcp_oauth cookie the browser holds */
    private ?string $cookie = null;
    /** @var string the last Set-Cookie header seen */
    private string $lastSetCookie = '';

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $this->store = new InMemoryStore();
        $this->accounts = new FakeAccountGateway();
        $this->accounts->addUser(1, 'alice');
        $this->config = Config::fromArray(['base_url' => self::BASE]);
        $this->clients = new ClientRegistry(
            $this->store,
            new CimdFetcher(new FakeHttpFetcher(), function () {
                return ['93.184.216.34'];
            }),
            $this->config,
            $this->clock
        );
        $this->client = $this->clients->register(
            ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]],
            '203.0.113.1'
        );
        $this->server = new AuthorizationServer(
            $this->store,
            $this->clients,
            $this->accounts,
            new Metadata(self::BASE, true, true),
            $this->clock,
            $this->config
        );
    }

    private function action(?Config $config = null, ?RequestContext $context = null): AuthorizeAction
    {
        return new AuthorizeAction(
            $config ?? $this->config,
            $this->server,
            $this->accounts,
            new Pages(),
            McpSession::key(self::SECRET),
            $this->clock,
            $context
        );
    }

    /** @return array<string, string> */
    private function query(array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => $this->client->clientId(),
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'scope' => 'read',
            'state' => 'st4te',
            'resource' => self::BASE . '/',
        ], $overrides);
    }

    /** @param array<string, string> $server */
    private function get(array $query, array $server = []): ResponseInterface
    {
        $request = Requests::get(self::PATH, $query, $server, $this->cookies());
        return $this->keep($this->action()($request, Requests::response()));
    }

    /** POSTs the form back as the page would: the OAuth parameters as hidden fields. */
    private function post(array $fields, ?array $query = null): ResponseInterface
    {
        $query = $query ?? $this->query();
        $request = Requests::form(self::PATH, array_merge($query, $fields), [], [], $this->cookies());
        return $this->keep($this->action()($request, Requests::response()));
    }

    /** @return array<string, string> */
    private function cookies(): array
    {
        return $this->cookie === null ? [] : [McpSession::COOKIE => $this->cookie];
    }

    /** Plays the browser's cookie jar. */
    private function keep(ResponseInterface $response): ResponseInterface
    {
        $this->lastSetCookie = $response->getHeaderLine('Set-Cookie');
        if ($this->lastSetCookie !== '') {
            $value = (string) substr(explode(';', $this->lastSetCookie)[0], strlen(McpSession::COOKIE) + 1);
            $this->cookie = strpos($this->lastSetCookie, 'Max-Age=0') !== false ? null : $value;
        }
        return $response;
    }

    /** The CSRF token of the last page's form. */
    private function csrf(ResponseInterface $page): string
    {
        $found = preg_match('/name="csrf" value="([0-9a-f]{64})"/', (string) $page->getBody(), $m);
        $this->assertSame(1, $found, 'the page has a CSRF field');
        return $m[1];
    }

    /** The session as the action would read it now. */
    private function session(): McpSession
    {
        return McpSession::fromRequest(
            Requests::get(self::PATH, [], [], $this->cookies()),
            McpSession::key(self::SECRET),
            '/modules/mcp/oauth/',
            true,
            $this->clock
        );
    }

    private function signIn(string $user = 'alice', array $query = []): ResponseInterface
    {
        $page = $this->get($this->query($query));
        return $this->post([
            'action' => 'login', 'company' => '1', 'user' => $user, 'password' => 'secret',
            'csrf' => $this->csrf($page),
        ], $this->query($query));
    }

    private static function text(ResponseInterface $response): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $response->getBody())));
    }

    private function assertHtmlHeaders(ResponseInterface $res): void
    {
        $this->assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString("frame-ancestors 'none'", $res->getHeaderLine('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", $res->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('DENY', $res->getHeaderLine('X-Frame-Options'));
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('no-referrer', $res->getHeaderLine('Referrer-Policy'));
    }

    public function testUnknownClientIsA400PageAndNoRedirect(): void
    {
        $res = $this->get($this->query(['client_id' => 'nope']));
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('', $res->getHeaderLine('Location'));
        $this->assertHtmlHeaders($res);
    }

    public function testUnregisteredRedirectUriIsA400PageAndNotRedirectedTo(): void
    {
        $res = $this->get($this->query(['redirect_uri' => 'https://evil.example/cb']));
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('', $res->getHeaderLine('Location'));
        $this->assertHtmlHeaders($res);
        $this->assertStringContainsString('redirect_uri is not registered', self::text($res));
    }

    public function testMissingChallengeRedirectsWithTheError(): void
    {
        $res = $this->get($this->query(['code_challenge' => '']));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith(self::REDIRECT . '?', $res->getHeaderLine('Location'));
        $params = Requests::locationQuery($res);
        $this->assertSame('invalid_request', $params['error']);
        $this->assertSame('st4te', $params['state']);
        $this->assertSame(self::BASE, $params['iss']);
        $this->assertArrayHasKey('error_description', $params);
    }

    public function testNotSignedInShowsTheLoginFormWithTheParametersKept(): void
    {
        $res = $this->get($this->query());
        $html = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertHtmlHeaders($res);
        $this->assertStringContainsString('name="user"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertMatchesRegularExpression(
            '/<input type="hidden" name="code_challenge" value="' . preg_quote(self::CHALLENGE, '/') . '"/',
            $html
        );
        $this->assertSame($this->session()->csrf(), $this->csrf($res));
        $this->assertStringContainsString('value="login"', $html);
        $this->assertStringContainsString(
            "form-action 'self' https://claude.ai;",
            $res->getHeaderLine('Content-Security-Policy')
        );
        $this->assertStringContainsString('; Path=/modules/mcp/oauth/', $this->lastSetCookie);
    }

    public function testTheLoginFormOffersEachCompany(): void
    {
        $html = (string) $this->get($this->query())->getBody();
        $this->assertStringContainsString('<select name="company"', $html);
        $this->assertStringContainsString('<option value="0">Demo</option>', $html);
        $this->assertStringContainsString('<option value="1">Training</option>', $html);
        $this->assertStringContainsString('<option value="2">Branch</option>', $html);
    }

    public function testWithOneCompanyTheCompanyIsAHiddenField(): void
    {
        $this->accounts->companies = [0 => 'Demo'];
        $html = (string) $this->get($this->query())->getBody();
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringContainsString('<input type="hidden" name="company" value="0">', $html);
    }

    public function testBadCredentialsShowTheFormAgain(): void
    {
        $page = $this->get($this->query());
        $res = $this->post([
            'action' => 'login', 'company' => '1', 'user' => 'alice', 'password' => 'wrong',
            'csrf' => $this->csrf($page),
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertHtmlHeaders($res);
        $this->assertStringContainsString('The user name or password is incorrect.', self::text($res));
        $this->assertStringContainsString('name="password"', (string) $res->getBody());
        $this->assertNull($this->session()->signedIn());
    }

    public function testAUserWithoutApiAccessGetsA403Page(): void
    {
        $this->accounts->addUser(1, 'bob', 'secret', true, false);
        $page = $this->get($this->query());
        $res = $this->post([
            'action' => 'login', 'company' => '1', 'user' => 'bob', 'password' => 'secret',
            'csrf' => $this->csrf($page),
        ]);
        $this->assertSame(403, $res->getStatusCode());
        $this->assertHtmlHeaders($res);
        $this->assertStringContainsString(
            'Your FrontAccounting role does not have GraphQL API access.',
            self::text($res)
        );
        $this->assertStringNotContainsString('name="password"', (string) $res->getBody());
        $this->assertNull($this->session()->signedIn());
    }

    public function testACompanyThatCannotBeOpenedShowsTheFormAgain(): void
    {
        $this->accounts->unavailable = [1];
        $page = $this->get($this->query());
        $res = $this->post([
            'action' => 'login', 'company' => '1', 'user' => 'alice', 'password' => 'secret',
            'csrf' => $this->csrf($page),
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('That company is not available.', self::text($res));
        $this->assertStringContainsString('name="password"', (string) $res->getBody());
        $this->assertNull($this->session()->signedIn());
    }

    public function testACompanyNotOnTheListIsNotTried(): void
    {
        $page = $this->get($this->query());
        foreach (['9', 'x', ''] as $company) {
            $res = $this->post([
                'action' => 'login', 'company' => $company, 'user' => 'alice', 'password' => 'secret',
                'csrf' => $this->csrf($page),
            ]);
            $this->assertSame(200, $res->getStatusCode());
            $this->assertStringContainsString('That company is not available.', self::text($res));
        }
        $this->assertSame([], $this->accounts->authentications);
    }

    public function testLoginWithoutCsrfIsRefused(): void
    {
        $this->get($this->query());
        $res = $this->post(['action' => 'login', 'company' => '1', 'user' => 'alice', 'password' => 'secret']);
        $this->assertSame(400, $res->getStatusCode());
        $this->assertHtmlHeaders($res);
        $this->assertNull($this->session()->signedIn());
        $this->assertSame([], $this->accounts->authentications, 'no password check without the CSRF token');
    }

    public function testLoginWithWrongCsrfIsRefused(): void
    {
        $this->get($this->query());
        $res = $this->post([
            'action' => 'login', 'company' => '1', 'user' => 'alice', 'password' => 'secret',
            'csrf' => str_repeat('0', 64),
        ]);
        $this->assertSame(400, $res->getStatusCode());
        $this->assertNull($this->session()->signedIn());
    }

    public function testLoginShowsConsent(): void
    {
        $res = $this->signIn();
        $html = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertHtmlHeaders($res);
        $this->assertSame(['company' => 1, 'login' => 'alice'], $this->session()->signedIn());
        $this->assertStringContainsString('Claude wants to use FrontAccounting as alice in Training', self::text($res));
        $this->assertStringContainsString('value="allow"', $html);
        $this->assertStringContainsString('value="deny"', $html);
        $this->assertStringContainsString('Read-only', $html);
        $this->assertStringContainsString('Read and change', $html);
        $this->assertMatchesRegularExpression('/value="read"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="write"\s+checked/', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }

    public function testTheLoginFrontAccountingReturnsIsTheOneKept(): void
    {
        $res = $this->signIn('ALICE');
        $this->assertSame(['company' => 1, 'login' => 'alice'], $this->session()->signedIn());
        $this->assertStringContainsString('as alice in Training', self::text($res));
    }

    public function testNoScopePreselectsRead(): void
    {
        $query = $this->query();
        unset($query['scope']);
        $page = $this->get($query);
        $html = (string) $this->post([
            'action' => 'login', 'company' => '1', 'user' => 'alice', 'password' => 'secret',
            'csrf' => $this->csrf($page),
        ], $query)->getBody();
        $this->assertMatchesRegularExpression('/value="read"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="write"\s+checked/', $html);
    }

    public function testWriteScopePreselectsWrite(): void
    {
        $html = (string) $this->signIn('alice', ['scope' => 'write'])->getBody();
        $this->assertMatchesRegularExpression('/value="write"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="read"\s+checked/', $html);
    }

    public function testASignedInGetShowsConsentAgain(): void
    {
        $this->signIn();
        $res = $this->get($this->query());
        $this->assertStringContainsString('value="allow"', (string) $res->getBody());
        $this->assertSame('', $this->lastSetCookie, 'an unchanged session is not re-sent');
    }

    public function testAllowRedirectsWithCodeStateAndIssuer(): void
    {
        $consent = $this->signIn();
        $res = $this->post(['action' => 'allow', 'granted_scope' => 'write', 'csrf' => $this->csrf($consent)]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith(self::REDIRECT . '?', $res->getHeaderLine('Location'));
        $params = Requests::locationQuery($res);
        $this->assertSame(64, strlen($params['code']));
        $this->assertSame('st4te', $params['state']);
        $this->assertSame(self::BASE, $params['iss']);
        $row = $this->store->findCode(hash('sha256', $params['code']));
        $this->assertSame(1, $row['company']);
        $this->assertSame('alice', $row['login']);
        $this->assertSame('write', $row['scope']);
        $this->assertStringContainsString('Max-Age=0', $this->lastSetCookie, 'the session is cleared');
        $this->assertNull($this->cookie);
    }

    public function testAllowWithoutAChoiceGrantsTheRequestedScope(): void
    {
        $consent = $this->signIn('alice', ['scope' => 'write']);
        $res = $this->post(['action' => 'allow', 'csrf' => $this->csrf($consent)], $this->query(['scope' => 'write']));
        $row = $this->store->findCode(hash('sha256', Requests::locationQuery($res)['code']));
        $this->assertSame('write', $row['scope']);
    }

    public function testDenyRedirectsWithAccessDenied(): void
    {
        $consent = $this->signIn();
        $res = $this->post(['action' => 'deny', 'csrf' => $this->csrf($consent)]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith(self::REDIRECT . '?', $res->getHeaderLine('Location'));
        $params = Requests::locationQuery($res);
        $this->assertSame('access_denied', $params['error']);
        $this->assertSame('st4te', $params['state']);
        $this->assertSame(self::BASE, $params['iss']);
        $this->assertArrayNotHasKey('code', $params);
        $this->assertStringContainsString('Max-Age=0', $this->lastSetCookie, 'the session is cleared');
    }

    public function testAStatelessRequestRedirectsWithoutState(): void
    {
        $query = $this->query();
        unset($query['state']);
        $page = $this->get($query);
        $consent = $this->post([
            'action' => 'login', 'company' => '1', 'user' => 'alice', 'password' => 'secret',
            'csrf' => $this->csrf($page),
        ], $query);
        $params = Requests::locationQuery($this->post(['action' => 'allow', 'csrf' => $this->csrf($consent)], $query));
        $this->assertArrayHasKey('code', $params);
        $this->assertArrayNotHasKey('state', $params);
    }

    public function testAllowWithBadCsrfIs400(): void
    {
        $this->signIn();
        $res = $this->post(['action' => 'allow', 'granted_scope' => 'read', 'csrf' => str_repeat('f', 64)]);
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('', $res->getHeaderLine('Location'));
        $this->assertHtmlHeaders($res);
    }

    public function testAllowWithoutCsrfIs400(): void
    {
        $this->signIn();
        $res = $this->post(['action' => 'allow', 'granted_scope' => 'read']);
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('', $res->getHeaderLine('Location'));
    }

    public function testAllowWithoutSigningInShowsTheLoginForm(): void
    {
        $page = $this->get($this->query());
        $res = $this->post(['action' => 'allow', 'granted_scope' => 'read', 'csrf' => $this->csrf($page)]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('', $res->getHeaderLine('Location'));
        $this->assertStringContainsString('name="password"', (string) $res->getBody());
    }

    public function testTheSignInLastsTenMinutes(): void
    {
        $consent = $this->signIn();
        $this->clock->advance(600);
        $this->assertStringContainsString('value="allow"', (string) $this->get($this->query())->getBody());

        $this->clock->advance(1);
        $this->assertStringContainsString('name="password"', (string) $this->get($this->query())->getBody());

        $res = $this->post(['action' => 'allow', 'granted_scope' => 'read', 'csrf' => $this->csrf($consent)]);
        $this->assertSame('', $res->getHeaderLine('Location'), 'no code after the sign-in expired');
        $this->assertStringContainsString('name="password"', (string) $res->getBody());
    }

    public function testATamperedCookieIsSignedOut(): void
    {
        $consent = $this->signIn();
        [$payload, $signature] = explode('.', (string) $this->cookie);
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $data['login'] = 'admin';
        $this->cookie = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=') . '.' . $signature;

        $this->assertStringContainsString('name="password"', (string) $this->get($this->query())->getBody());
        $this->assertNull($this->session()->signedIn());

        $res = $this->post(['action' => 'allow', 'granted_scope' => 'read', 'csrf' => $this->csrf($consent)]);
        $this->assertSame(400, $res->getStatusCode(), 'the forged cookie\'s CSRF token is not believed');
        $this->assertSame('', $res->getHeaderLine('Location'));
    }

    public function testClientNameIsEscaped(): void
    {
        $evil = $this->clients->register(
            ['client_name' => '<script>alert(1)</script>', 'redirect_uris' => [self::REDIRECT]],
            '203.0.113.1'
        );
        $html = (string) $this->signIn('alice', ['client_id' => $evil->clientId()])->getBody();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testCompanyNamesAreEscaped(): void
    {
        $this->accounts->companies = [0 => 'A & "B" <C>', 1 => 'D'];
        $html = (string) $this->get($this->query())->getBody();
        $this->assertStringContainsString('A &amp; &quot;B&quot; &lt;C&gt;', $html);
    }

    public function testParametersAreEscapedInHiddenFields(): void
    {
        $html = (string) $this->get($this->query(['state' => '"><script>x</script>']))->getBody();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
    }

    public function testPlainHttpIsAnErrorPageWhenTlsIsRequired(): void
    {
        $request = Requests::get(self::PATH, $this->query(), ['HTTPS' => '']);
        $res = $this->action()($request, Requests::response());
        $this->assertSame(400, $res->getStatusCode());
        $this->assertHtmlHeaders($res);
        $this->assertStringContainsString('HTTPS is required', self::text($res));
    }

    public function testPlainHttpIsServedWhenTlsIsNotRequired(): void
    {
        $config = Config::fromArray(['base_url' => self::BASE, 'require_tls' => false]);
        $request = Requests::get(self::PATH, $this->query(), ['HTTPS' => '']);
        $res = $this->action($config)($request, Requests::response());
        $this->assertSame(200, $res->getStatusCode());
        // The cookie follows base_url: an https base_url makes it Secure even
        // when this hop was plain HTTP (TLS ended at a proxy not trusted).
        $this->assertStringContainsString('; Secure', $res->getHeaderLine('Set-Cookie'));
    }

    public function testTheCookieIsNotSecureForAPlainHttpBaseUrl(): void
    {
        $config = Config::fromArray(['base_url' => 'http://fa.local/modules/mcp', 'require_tls' => false]);
        $request = Requests::get(self::PATH, $this->query(), ['HTTPS' => '']);
        $res = $this->action($config)($request, Requests::response());
        $this->assertSame(200, $res->getStatusCode());
        $this->assertNotSame('', $res->getHeaderLine('Set-Cookie'));
        $this->assertStringNotContainsString('Secure', $res->getHeaderLine('Set-Cookie'));
    }

    public function testTheInjectedRequestContextDecidesHttps(): void
    {
        // The container's context (trust_proxy on, X-Forwarded-Proto https)
        // is the one used; the action does not rebuild it from the request.
        $request = Requests::get(self::PATH, $this->query(), ['HTTPS' => ''])
            ->withHeader('X-Forwarded-Proto', 'https');
        $res = $this->action(null, RequestContext::fromRequest($request, true))($request, Requests::response());
        $this->assertSame(200, $res->getStatusCode());
        $res = $this->action(null, RequestContext::fromRequest($request, false))($request, Requests::response());
        $this->assertSame(400, $res->getStatusCode());
    }

    public function testAnUnexpectedFailureIsAGeneric500Page(): void
    {
        $this->accounts->companiesFailure = new \RuntimeException('db down secret');
        $res = $this->get($this->query());
        $this->assertSame(500, $res->getStatusCode());
        $this->assertHtmlHeaders($res);
        $this->assertStringNotContainsString('secret', (string) $res->getBody());
    }
}
