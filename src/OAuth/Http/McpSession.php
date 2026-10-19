<?php

namespace FA\Mcp\OAuth\Http;

use FA\Mcp\OAuth\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The authorize pages' own state (spec §5.3 step 6): who signed in on the
 * login form, for at most SIGN_IN_SECONDS, and the forms' CSRF token.
 *
 * Not a PHP session: graphql's Executor boots FrontAccounting, which resets
 * $_SESSION and the superglobals. The state is the cookie itself instead,
 * fa_mcp_oauth = base64url(JSON) "." hex HMAC-SHA256 of that, under a key
 * derived from graphql's secret. A cookie whose signature does not verify is
 * ignored whole, so it signs no one in and carries no CSRF token.
 *
 * It never creates a FrontAccounting login session. The cookie is scoped to
 * the module's /oauth/ path, HttpOnly, SameSite=Lax, and Secure when the
 * module is served over https.
 */
final class McpSession
{
    public const COOKIE = 'fa_mcp_oauth';
    public const SIGN_IN_SECONDS = 600;

    private string $key;
    private string $path;
    private bool $secure;
    private Clock $clock;
    /** @var array{company: ?int, login: ?string, signedInAt: ?int, csrf: ?string} */
    private array $data;
    private bool $changed = false;
    private bool $cleared = false;

    /** @param array{company: ?int, login: ?string, signedInAt: ?int, csrf: ?string} $data */
    private function __construct(string $key, string $path, bool $secure, Clock $clock, array $data)
    {
        $this->key = $key;
        $this->path = $path;
        $this->secure = $secure;
        $this->clock = $clock;
        $this->data = $data;
    }

    /** The signing key, from graphql's Config secret. */
    public static function key(string $graphqlSecret): string
    {
        return hash_hmac('sha256', 'fa-mcp-oauth-session', $graphqlSecret);
    }

    /** base_url's path + '/oauth/': the cookie goes to the authorize URL only. */
    public static function cookiePath(string $baseUrl): string
    {
        return rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/') . '/oauth/';
    }

    /**
     * @param string $key self::key()
     * @param string $path self::cookiePath()
     * @param bool $secure whether the cookie is Secure: the module is served over https
     */
    public static function fromRequest(
        ServerRequestInterface $request,
        string $key,
        string $path,
        bool $secure,
        Clock $clock
    ): self {
        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;
        $data = is_string($cookie) ? self::decode($cookie, $key) : null;
        return new self($key, $path, $secure, $clock, $data ?? self::empty());
    }

    /**
     * The user signed in on the login form within SIGN_IN_SECONDS. An expired
     * sign-in is dropped from the cookie.
     *
     * @return array{company: int, login: string}|null
     */
    public function signedIn(): ?array
    {
        $d = $this->data;
        if ($d['company'] === null || $d['login'] === null || $d['signedInAt'] === null) {
            return null;
        }
        if ($d['signedInAt'] + self::SIGN_IN_SECONDS < $this->clock->now()) {
            $this->data = ['company' => null, 'login' => null, 'signedInAt' => null] + $this->data;
            $this->changed = true;
            return null;
        }
        return ['company' => $d['company'], 'login' => $d['login']];
    }

    /**
     * After a successful login. A new CSRF token comes with it, so a token
     * planted before the sign-in is not the one that now acts for the user.
     */
    public function signIn(int $company, string $login): void
    {
        $this->data = [
            'company' => $company,
            'login' => $login,
            'signedInAt' => $this->clock->now(),
            'csrf' => bin2hex(random_bytes(32)),
        ];
        $this->changed = true;
        $this->cleared = false;
    }

    public function csrf(): ?string
    {
        return $this->data['csrf'];
    }

    public function setCsrf(string $token): void
    {
        $this->data['csrf'] = $token;
        $this->changed = true;
        $this->cleared = false;
    }

    /** After Allow or Deny: the cookie is removed. */
    public function clear(): void
    {
        $this->data = self::empty();
        $this->changed = true;
        $this->cleared = true;
    }

    /** $response with the Set-Cookie this session needs; unchanged when nothing changed. */
    public function withCookie(ResponseInterface $response): ResponseInterface
    {
        if (!$this->changed) {
            return $response;
        }
        $attributes = '; Path=' . $this->path . '; HttpOnly; SameSite=Lax' . ($this->secure ? '; Secure' : '');
        if ($this->cleared) {
            return $response->withAddedHeader(
                'Set-Cookie',
                self::COOKIE . '=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT' . $attributes
            );
        }
        return $response->withAddedHeader('Set-Cookie', self::COOKIE . '=' . $this->encode() . $attributes);
    }

    private function encode(): string
    {
        $payload = self::base64Url((string) json_encode($this->data));
        return $payload . '.' . hash_hmac('sha256', $payload, $this->key);
    }

    /** @return array{company: ?int, login: ?string, signedInAt: ?int, csrf: ?string}|null */
    private static function decode(string $cookie, string $key): ?array
    {
        $parts = explode('.', $cookie);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $key), $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $out = self::empty();
        $out['company'] = isset($data['company']) && is_int($data['company']) ? $data['company'] : null;
        $out['login'] = isset($data['login']) && is_string($data['login']) ? $data['login'] : null;
        $out['signedInAt'] = isset($data['signedInAt']) && is_int($data['signedInAt']) ? $data['signedInAt'] : null;
        $out['csrf'] = isset($data['csrf']) && is_string($data['csrf']) && $data['csrf'] !== '' ? $data['csrf'] : null;
        return $out;
    }

    /** @return array{company: null, login: null, signedInAt: null, csrf: null} */
    private static function empty(): array
    {
        return ['company' => null, 'login' => null, 'signedInAt' => null, 'csrf' => null];
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
