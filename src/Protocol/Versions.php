<?php

namespace FA\Mcp\Protocol;

/** The MCP protocol revisions this server speaks (spec §6.1). */
final class Versions
{
    /** The handshake-less revision: version and client info travel in each request's _meta. */
    public const MODERN = '2026-07-28';
    /** The newest revision with the initialize handshake; answered to an unknown requested version. */
    public const LATEST_LEGACY = '2025-11-25';
    public const SUPPORTED = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];
    /** The legacy version assumed when a request carries no MCP-Protocol-Version header. */
    public const LEGACY_DEFAULT = '2025-03-26';

    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }
}
