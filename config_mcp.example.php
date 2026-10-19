<?php

/*
    Copy to config_mcp.php to change a setting; every key is optional and shows
    its default. With no config_mcp.php the defaults apply and OAuth is off (no
    base_url): bearer-only use works out of the box.
*/

return [
    // Canonical public URL of the module, e.g. 'https://fa.example.com/modules/mcp'
    // (http(s), no trailing slash). Required for OAuth.
    // 'base_url' => '',

    // Serve the OAuth endpoints and metadata (only when base_url is set).
    // 'oauth' => true,

    // Refuse plain HTTP (graphql's trust_proxy decides forwarded HTTPS).
    // 'require_tls' => true,

    // Origins accepted besides base_url's own.
    // 'allowed_origins' => ['https://claude.ai'],

    // 'access_token_ttl' => 3600,       // seconds
    // 'refresh_token_ttl' => 2592000,   // seconds, 30 days

    // The query.limit injected into list tools (1 to 1000).
    // 'default_list_limit' => 50,

    // List the graphql_* escape-hatch tools.
    // 'escape_hatch' => true,

    // Offer RFC 7591 dynamic client registration.
    // 'allow_dcr' => true,

    // Accept Client ID Metadata Documents.
    // 'allow_cimd' => true,

    // 'cimd_cache_ttl' => 86400,        // seconds

    // Registrations (and new CIMD lookups) per source IP per hour.
    // 'dcr_rate_limit' => 10,

    // Exception messages in error responses.
    // 'debug' => false,
];
