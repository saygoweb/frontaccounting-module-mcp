<?php

namespace FA\Mcp\OAuth;

/**
 * An RFC 6749 error. The HTTP layer turns it into a JSON body at the token
 * and registration endpoints. At /oauth/authorize it becomes a redirect back
 * to the client only when redirectable() says so - that is, only once the
 * client and its redirect URI have been validated. Before that, sending the
 * user to an unchecked URI would make the module an open redirector, so the
 * error is shown as a page instead.
 *
 * A server_error may carry the exception behind it as getPrevious(), for the
 * HTTP layer to log; it never reaches the response.
 */
final class OAuthError extends \RuntimeException
{
    private string $error;
    private string $description;
    private int $httpStatus;
    private bool $redirectable;

    public function __construct(
        string $error,
        string $description = '',
        int $httpStatus = 400,
        bool $redirectable = false,
        ?\Throwable $previous = null
    ) {
        parent::__construct($description !== '' ? $error . ': ' . $description : $error, 0, $previous);
        $this->error = $error;
        $this->description = $description;
        $this->httpStatus = $httpStatus;
        $this->redirectable = $redirectable;
    }

    public function error(): string
    {
        return $this->error;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function redirectable(): bool
    {
        return $this->redirectable;
    }

    /** The response body. error_description is optional in RFC 6749, so it is left out when empty. */
    public function toArray(): array
    {
        $body = ['error' => $this->error];
        if ($this->description !== '') {
            $body['error_description'] = $this->description;
        }
        return $body;
    }
}
