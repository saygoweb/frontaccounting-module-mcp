<?php

namespace FA\Mcp\OAuth\Http;

/** The login and consent forms' CSRF token: one random value per McpSession. */
final class Csrf
{
    private McpSession $session;

    public function __construct(McpSession $session)
    {
        $this->session = $session;
    }

    /** The session's token, made on first use. */
    public function token(): string
    {
        $token = $this->session->csrf();
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            $this->session->setCsrf($token);
        }
        return $token;
    }

    /** @param mixed $presented the form's csrf field, whatever was sent */
    public function isValid($presented): bool
    {
        $token = $this->session->csrf();
        return $token !== null && is_string($presented) && hash_equals($token, $presented);
    }
}
