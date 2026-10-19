<?php

namespace FA\Mcp\Tests\Unit\OAuth\Http;

use FA\Mcp\OAuth\Http\McpSession;
use FA\Mcp\Tests\Support\FixedClock;
use FA\Mcp\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class McpSessionTest extends TestCase
{
    private const SECRET = 'a-graphql-secret-of-at-least-32-bytes!!';
    private const PATH = '/modules/mcp/oauth/';

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
    }

    private static function key(): string
    {
        return McpSession::key(self::SECRET);
    }

    /** @param string|null $cookie the fa_mcp_oauth value the browser sends */
    private function session(?string $cookie, bool $secure = true, string $key = ''): McpSession
    {
        $request = Requests::get('/modules/mcp/oauth/authorize', [], [], $cookie === null ? [] : [
            McpSession::COOKIE => $cookie,
        ]);
        return McpSession::fromRequest($request, $key !== '' ? $key : self::key(), self::PATH, $secure, $this->clock);
    }

    /** The fa_mcp_oauth value a response sets; null when it sets none. */
    private function setCookie(ResponseInterface $response): ?string
    {
        $header = $response->getHeaderLine('Set-Cookie');
        if ($header === '') {
            return null;
        }
        $this->assertStringStartsWith(McpSession::COOKIE . '=', $header);
        return (string) substr(explode(';', $header)[0], strlen(McpSession::COOKIE) + 1);
    }

    private function signedInCookie(int $company = 1, string $login = 'alice'): string
    {
        $session = $this->session(null);
        $session->signIn($company, $login);
        $session->setCsrf('token-1');
        return (string) $this->setCookie($session->withCookie(Requests::response()));
    }

    public function testSigningInMakesANewCsrfToken(): void
    {
        $session = $this->session(null);
        $session->setCsrf('planted');
        $session->signIn(1, 'alice');
        $this->assertNotSame('planted', $session->csrf());
        $this->assertSame(64, strlen((string) $session->csrf()));
    }

    public function testTheKeyIsDerivedFromTheGraphQlSecret(): void
    {
        $this->assertSame(hash_hmac('sha256', 'fa-mcp-oauth-session', self::SECRET), McpSession::key(self::SECRET));
    }

    public function testTheCookiePathIsTheBaseUrlsPathPlusOAuth(): void
    {
        $this->assertSame('/modules/mcp/oauth/', McpSession::cookiePath('https://fa.example.com/modules/mcp'));
        $this->assertSame('/oauth/', McpSession::cookiePath('https://mcp.example.com'));
    }

    public function testNoCookieIsSignedOutAndSetsNothing(): void
    {
        $session = $this->session(null);
        $this->assertNull($session->signedIn());
        $this->assertNull($session->csrf());
        $this->assertSame('', $session->withCookie(Requests::response())->getHeaderLine('Set-Cookie'));
    }

    public function testSignInRoundTripsThroughTheCookie(): void
    {
        $session = $this->session($this->signedInCookie(2, 'Alice'));
        $this->assertSame(['company' => 2, 'login' => 'Alice'], $session->signedIn());
        $this->assertSame('token-1', $session->csrf());
    }

    public function testTheCookieAttributes(): void
    {
        $session = $this->session(null);
        $session->setCsrf('t');
        $header = $session->withCookie(Requests::response())->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('; Path=/modules/mcp/oauth/', $header);
        $this->assertStringContainsString('; HttpOnly', $header);
        $this->assertStringContainsString('; SameSite=Lax', $header);
        $this->assertStringContainsString('; Secure', $header);

        $plain = $this->session(null, false);
        $plain->setCsrf('t');
        $header = $plain->withCookie(Requests::response())->getHeaderLine('Set-Cookie');
        $this->assertStringNotContainsString('Secure', $header);
    }

    public function testTheCookieIsSignedJson(): void
    {
        $cookie = $this->signedInCookie(1, 'alice');
        [$payload, $signature] = explode('.', $cookie);
        $this->assertSame(hash_hmac('sha256', $payload, self::key()), $signature);
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $this->assertSame(1, $data['company']);
        $this->assertSame('alice', $data['login']);
        $this->assertSame($this->clock->now(), $data['signedInAt']);
        $this->assertSame('token-1', $data['csrf']);
    }

    public function testATamperedCookieIsSignedOut(): void
    {
        $cookie = $this->signedInCookie(1, 'alice');
        [$payload, $signature] = explode('.', $cookie);
        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        $data['login'] = 'admin';
        $forged = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=') . '.' . $signature;

        $session = $this->session($forged);
        $this->assertNull($session->signedIn());
        $this->assertNull($session->csrf(), 'nothing of a forged cookie is believed');
    }

    public function testACookieSignedWithAnotherKeyIsSignedOut(): void
    {
        $session = $this->session($this->signedInCookie(), true, McpSession::key(str_repeat('x', 40)));
        $this->assertNull($session->signedIn());
    }

    /** @return array<string, array{0: string}> */
    public function garbage(): array
    {
        return [
            'empty' => [''],
            'no dot' => ['abc'],
            'bad signature' => ['eyJhIjoxfQ.0000'],
            'not json' => [rtrim(strtr(base64_encode('nope'), '+/', '-_'), '=') . '.' . hash_hmac(
                'sha256',
                rtrim(strtr(base64_encode('nope'), '+/', '-_'), '='),
                McpSession::key(self::SECRET)
            )],
        ];
    }

    /** @dataProvider garbage */
    public function testGarbageIsSignedOut(string $cookie): void
    {
        $this->assertNull($this->session($cookie)->signedIn());
    }

    public function testTheSignInLastsTenMinutes(): void
    {
        $cookie = $this->signedInCookie();
        $this->clock->advance(McpSession::SIGN_IN_SECONDS);
        $this->assertNotNull($this->session($cookie)->signedIn());

        $this->clock->advance(1);
        $expired = $this->session($cookie);
        $this->assertNull($expired->signedIn());
        $this->assertSame('token-1', $expired->csrf(), 'the CSRF token outlives the sign-in');

        $rewritten = $this->setCookie($expired->withCookie(Requests::response()));
        $this->assertNotNull($rewritten, 'the expired sign-in is dropped from the cookie');
        $this->assertNull($this->session((string) $rewritten)->signedIn());
    }

    public function testClearExpiresTheCookie(): void
    {
        $session = $this->session($this->signedInCookie());
        $session->clear();
        $this->assertNull($session->signedIn());
        $this->assertNull($session->csrf());
        $header = $session->withCookie(Requests::response())->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith(McpSession::COOKIE . '=;', $header);
        $this->assertStringContainsString('Max-Age=0', $header);
        $this->assertStringContainsString('; Path=/modules/mcp/oauth/', $header);
    }

    public function testAnUnchangedSessionSetsNoCookie(): void
    {
        $session = $this->session($this->signedInCookie());
        $session->signedIn();
        $session->csrf();
        $this->assertSame('', $session->withCookie(Requests::response())->getHeaderLine('Set-Cookie'));
    }
}
