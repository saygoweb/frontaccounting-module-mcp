<?php

namespace FA\Mcp\OAuth\Http;

use FA\Mcp\Config;
use FA\Mcp\Gateway\AccountGateway;
use FA\Mcp\Gateway\AuthFailure;
use FA\Mcp\Http\RequestContext;
use FA\Mcp\Http\Responses;
use FA\Mcp\OAuth\AuthorizationServer;
use FA\Mcp\OAuth\Client;
use FA\Mcp\OAuth\Clock;
use FA\Mcp\OAuth\OAuthError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET and POST /oauth/authorize: sign in if needed, then consent (spec §5.3
 * steps 5 to 7).
 *
 * The client and its exact redirect URI are checked first; until both hold,
 * every failure is an HTML page, never a redirect. GET shows the login form or
 * the consent page. Every POST comes from one of those pages, carries the
 * authorize parameters back as hidden fields, and is checked against the
 * session's CSRF token before it does anything.
 *
 * The login form checks the password through AccountGateway::authenticate
 * (FrontAccounting's own check, login_delay throttling included). The sign-in
 * is kept in McpSession only, for at most ten minutes and until the user
 * allows or denies; it never creates a FrontAccounting login session.
 */
final class AuthorizeAction
{
    /** The authorize request's parameters: kept in the forms as hidden fields. */
    private const PARAMS = [
        'response_type', 'client_id', 'redirect_uri', 'code_challenge',
        'code_challenge_method', 'scope', 'state', 'resource',
    ];

    public const BAD_CREDENTIALS = 'The user name or password is incorrect.';
    public const COMPANY_UNAVAILABLE = 'That company is not available.';
    public const NO_API_ACCESS = 'Your FrontAccounting role does not have GraphQL API access.';

    private Config $config;
    private AuthorizationServer $server;
    private AccountGateway $accounts;
    private Pages $pages;
    private string $sessionKey;
    private Clock $clock;
    private ?RequestContext $context;

    /**
     * @param string $sessionKey McpSession::key() of graphql's secret
     * @param RequestContext|null $context the request's context, as the container
     *                                     builds it (graphql's trust_proxy applied);
     *                                     null reads the request itself, trusting no proxy
     */
    public function __construct(
        Config $config,
        AuthorizationServer $server,
        AccountGateway $accounts,
        Pages $pages,
        string $sessionKey,
        Clock $clock,
        ?RequestContext $context = null
    ) {
        $this->config = $config;
        $this->server = $server;
        $this->accounts = $accounts;
        $this->pages = $pages;
        $this->sessionKey = $sessionKey;
        $this->clock = $clock;
        $this->context = $context;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $ctx = $this->context ?? RequestContext::fromRequest($request, false);
            if ($this->config->requireTls && !$ctx->isHttps()) {
                return $this->errorPage($response, 400, 'HTTPS is required', 'Connect over https:// instead.', null);
            }
            // Secure whenever the module is served over https (base_url), not
            // only when this hop was: TLS may end at a proxy not trusted.
            $secure = $ctx->isHttps() || stripos($this->config->baseUrl, 'https://') === 0;
            $session = McpSession::fromRequest(
                $request,
                $this->sessionKey,
                McpSession::cookiePath($this->config->baseUrl),
                $secure,
                $this->clock
            );
            return $session->withCookie($this->handle($request, $response, $ctx, $session));
        } catch (\Throwable $e) {
            JsonEndpoint::log($e);
            return $this->errorPage(
                $response,
                500,
                'Something went wrong',
                'FrontAccounting could not complete this request. Try again later.',
                null
            );
        }
    }

    private function handle(
        ServerRequestInterface $request,
        ResponseInterface $res,
        RequestContext $ctx,
        McpSession $session
    ): ResponseInterface {
        $isPost = strtoupper($request->getMethod()) === 'POST';
        $body = $request->getParsedBody();
        $form = $isPost && is_array($body) ? $body : [];
        $params = $isPost ? self::pick($form) + self::pick($request->getQueryParams()) : $request->getQueryParams();
        $issuer = $this->config->baseUrl;

        try {
            $validated = $this->server->validateAuthorizeRequest($params, $ctx->clientIp());
        } catch (OAuthError $e) {
            if (!$e->redirectable()) {
                return $this->errorPage($res, 400, 'This app cannot be connected', $e->description(), null);
            }
            return self::redirect($res, (string) $params['redirect_uri'], [
                'error' => $e->error(),
                'error_description' => $e->description(),
                'state' => self::stringOrNull($params, 'state'),
                'iss' => $issuer,
            ]);
        }

        $redirectUri = (string) $validated['redirectUri'];
        $origin = self::origin($redirectUri);
        $csrf = new Csrf($session);
        $action = $isPost ? self::stringOrNull($form, 'action') : null;

        if ($isPost && !$csrf->isValid($form['csrf'] ?? null)) {
            return $this->errorPage(
                $res,
                400,
                'This page has expired',
                'Go back to your app and connect again.',
                $origin
            );
        }

        $signedIn = $session->signedIn();

        if ($action === 'login') {
            $failure = $this->logIn($form, $session);
            if ($failure === self::NO_API_ACCESS) {
                return $this->errorPage($res, 403, 'API access is off', self::NO_API_ACCESS, $origin);
            }
            if ($failure !== null) {
                return $this->loginPage($res, $validated, $params, $origin, $csrf, $form, $failure);
            }
            $signedIn = $session->signedIn();
        }

        if ($signedIn === null) {
            return $this->loginPage($res, $validated, $params, $origin, $csrf, [], null);
        }

        if ($action === 'allow') {
            $chosen = self::stringOrNull($form, 'granted_scope');
            $scope = $chosen === 'read' || $chosen === 'write' ? $chosen : (string) $validated['scope'];
            $code = $this->server->issueCode($validated, $signedIn['company'], $signedIn['login'], $scope);
            $session->clear();
            return self::redirect($res, $redirectUri, [
                'code' => $code,
                'state' => $validated['state'],
                'iss' => $issuer,
            ]);
        }

        if ($action === 'deny') {
            $session->clear();
            return self::redirect($res, $redirectUri, [
                'error' => 'access_denied',
                'error_description' => 'The user denied the request',
                'state' => $validated['state'],
                'iss' => $issuer,
            ]);
        }

        return $this->consentPage($res, $validated, $params, $origin, $csrf, $signedIn);
    }

    /**
     * The login form's POST: on success the session is signed in, as the
     * login FrontAccounting returns (its own spelling, not the one typed).
     *
     * @param array<string, mixed> $form
     * @return string|null null when signed in, else BAD_CREDENTIALS, COMPANY_UNAVAILABLE or NO_API_ACCESS
     */
    private function logIn(array $form, McpSession $session): ?string
    {
        $company = self::companyId($form, $this->accounts->companies());
        if ($company === null) {
            return self::COMPANY_UNAVAILABLE;
        }
        $user = (string) self::stringOrNull($form, 'user');
        $password = (string) self::stringOrNull($form, 'password');
        try {
            $result = $this->accounts->authenticate($company, $user, $password);
        } catch (AuthFailure $e) {
            // graphql cannot open the company: not a wrong password.
            return self::COMPANY_UNAVAILABLE;
        }
        if ($result['status'] === 'NO_API_ACCESS') {
            return self::NO_API_ACCESS;
        }
        if ($result['status'] !== 'OK' || $result['login'] === '') {
            return self::BAD_CREDENTIALS;
        }
        $session->signIn($company, $result['login']);
        return null;
    }

    /**
     * The form's company, when it is one of $companies.
     *
     * @param array<string, mixed> $form
     * @param array<int, string> $companies
     */
    private static function companyId(array $form, array $companies): ?int
    {
        $value = $form['company'] ?? null;
        if (!is_string($value) || !ctype_digit($value)) {
            return null;
        }
        return array_key_exists((int) $value, $companies) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $params
     * @param array<string, mixed> $form the failed login's fields, to fill the form again
     */
    private function loginPage(
        ResponseInterface $res,
        array $validated,
        array $params,
        string $origin,
        Csrf $csrf,
        array $form,
        ?string $error
    ): ResponseInterface {
        /** @var Client $client */
        $client = $validated['client'];
        $companies = $this->accounts->companies();
        return $this->page($res, 200, 'login', 'Sign in to connect ' . $client->name(), [
            'clientName' => $client->name(),
            'redirectHost' => self::hostOf((string) $validated['redirectUri']),
            'companies' => $companies,
            'company' => self::companyId($form, $companies),
            'user' => (string) self::stringOrNull($form, 'user'),
            'hidden' => self::pick($params),
            'csrf' => $csrf->token(),
            'error' => $error,
        ], $origin);
    }

    /**
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $params
     * @param array{company: int, login: string} $signedIn
     */
    private function consentPage(
        ResponseInterface $res,
        array $validated,
        array $params,
        string $origin,
        Csrf $csrf,
        array $signedIn
    ): ResponseInterface {
        /** @var Client $client */
        $client = $validated['client'];
        $companies = $this->accounts->companies();
        return $this->page($res, 200, 'consent', 'Connect ' . $client->name(), [
            'clientName' => $client->name(),
            'redirectHost' => self::hostOf((string) $validated['redirectUri']),
            'login' => $signedIn['login'],
            'companyName' => $companies[$signedIn['company']] ?? (string) $signedIn['company'],
            'scope' => (string) $validated['scope'],
            'hidden' => self::pick($params),
            'csrf' => $csrf->token(),
        ], $origin);
    }

    private function errorPage(
        ResponseInterface $res,
        int $status,
        string $heading,
        string $message,
        ?string $origin
    ): ResponseInterface {
        return $this->page($res, $status, 'error', $heading, ['heading' => $heading, 'message' => $message], $origin);
    }

    /** @param array<string, mixed> $vars */
    private function page(
        ResponseInterface $res,
        int $status,
        string $template,
        string $title,
        array $vars,
        ?string $origin
    ): ResponseInterface {
        $html = $this->pages->render($template, $title, $vars);
        return Responses::html($res, $status, $html, self::htmlHeaders($origin));
    }

    /**
     * The headers on every page. form-action names the client's origin as
     * well as 'self' because browsers apply it to the redirect that follows
     * the form's submission, not only to the form's own target.
     *
     * @return array<string, string>
     */
    private static function htmlHeaders(?string $origin): array
    {
        $formAction = "'self'" . ($origin !== null && $origin !== '' ? ' ' . $origin : '');
        return [
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action "
                . $formAction . "; frame-ancestors 'none'",
            'X-Frame-Options' => 'DENY',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ];
    }

    /**
     * A 302 to $uri with $params added to its query; null parameters are left out.
     *
     * @param array<string, string|null> $params
     */
    private static function redirect(ResponseInterface $res, string $uri, array $params): ResponseInterface
    {
        $query = http_build_query(array_filter($params, static function ($v): bool {
            return $v !== null;
        }), '', '&', PHP_QUERY_RFC3986);
        $location = $uri . (strpos($uri, '?') === false ? '?' : '&') . $query;
        return Responses::empty($res, 302, ['Location' => $location, 'Cache-Control' => 'no-store']);
    }

    /**
     * The authorize parameters in $source that are strings, in PARAMS order.
     *
     * @param array<string, mixed> $source
     * @return array<string, string>
     */
    private static function pick(array $source): array
    {
        $picked = [];
        foreach (self::PARAMS as $key) {
            if (isset($source[$key]) && is_string($source[$key])) {
                $picked[$key] = $source[$key];
            }
        }
        return $picked;
    }

    /** @param array<string, mixed> $source */
    private static function stringOrNull(array $source, string $key): ?string
    {
        return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : null;
    }

    /** scheme://host[:port] of a validated redirect URI. */
    private static function origin(string $uri): string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function hostOf(string $uri): string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }
        return $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
