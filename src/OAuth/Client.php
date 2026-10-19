<?php

namespace FA\Mcp\OAuth;

/** A registered (dcr) or metadata-document (cimd) OAuth client. Public clients only: no secret. */
final class Client
{
    private string $clientId;
    private string $kind;
    private string $name;
    /** @var string[] */
    private array $redirectUris;

    /** @param string[] $redirectUris */
    public function __construct(string $clientId, string $kind, string $name, array $redirectUris)
    {
        $this->clientId = $clientId;
        $this->kind = $kind;
        $this->name = $name;
        $this->redirectUris = array_values(array_map('strval', $redirectUris));
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    /** 'dcr' or 'cimd' */
    public function kind(): string
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return string[] */
    public function redirectUris(): array
    {
        return $this->redirectUris;
    }
}
