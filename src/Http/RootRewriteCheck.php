<?php

namespace FA\Mcp\Http;

use FA\Mcp\Config;

/**
 * Whether FrontAccounting's root .htaccess has spec §5.1's rule (README "OAuth
 * and the root rewrite rule"). Without it, a client that asks for the issuer's
 * metadata only at the origin root (RFC 8414) finds none and falls back to
 * POST /register at the origin, which the web server answers 404.
 *
 * The rule is needed only while OAuth is served, and FrontAccounting's root
 * .htaccess can serve it only when FrontAccounting is the origin root
 * (base_url's path is /modules/mcp). A rule that is commented out does not
 * count; one with other flags or inside <IfModule> does.
 */
final class RootRewriteCheck
{
    public const RULE = 'RewriteRule '
        . '^\.well-known/(oauth-authorization-server|openid-configuration|oauth-protected-resource)/modules/mcp/?$ '
        . 'modules/mcp/.well-known/$1 [L]';

    /** Why $htaccess (FrontAccounting's root .htaccess) needs the rule, or null. */
    public static function problem(Config $config, string $htaccess): ?string
    {
        if (
            !$config->oauthEnabled()
            || rtrim((string) parse_url($config->baseUrl, PHP_URL_PATH), '/') !== '/modules/mcp'
        ) {
            return null;
        }
        $contents = is_file($htaccess) ? (string) file_get_contents($htaccess) : '';
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if (strpos($line, 'RewriteRule ') === 0 && strpos($line, ' modules/mcp/.well-known/$1') !== false) {
                return null;
            }
        }
        return 'OAuth clients that look for the MCP module\'s metadata at the site root will fail to connect: '
            . 'add this rule to FrontAccounting\'s root .htaccess (see modules/mcp/README.md): ' . self::RULE;
    }
}
