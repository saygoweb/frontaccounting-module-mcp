<?php

namespace FA\Mcp\Http;

use FA\Mcp\Config;
use FA\Mcp\OAuth\Metadata;

/**
 * The WWW-Authenticate challenge of the MCP endpoint (spec §5.3, §5.5, §10).
 *
 * With OAuth served, resource_metadata points at the RFC 9728 document, which
 * is how a client that has never connected finds the authorization server.
 * Without it (no base_url, or oauth off) there is no document to name, and a
 * realm keeps the challenge well-formed (RFC 6750 §3).
 */
final class BearerChallenge
{
    public const INVALID_TOKEN = 'invalid_token';
    public const REALM = 'frontaccounting-mcp';

    /** @param string|null $error invalid_token, or null for a request with no token */
    public static function header(Config $config, ?string $error = null): string
    {
        $errorParam = $error === null ? '' : 'error="' . $error . '"';
        if (!$config->oauthEnabled()) {
            return 'Bearer realm="' . self::REALM . '"' . ($errorParam === '' ? '' : ', ' . $errorParam);
        }
        $metadata = new Metadata($config->baseUrl, $config->allowDcr, $config->allowCimd);
        return 'Bearer ' . ($errorParam === '' ? '' : $errorParam . ', ')
            . 'resource_metadata="' . $metadata->resourceMetadataUrl() . '"';
    }
}
