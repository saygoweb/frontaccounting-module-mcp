<?php

namespace FA\Mcp\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Spec §5.1's optional rule in FrontAccounting's root .htaccess rewrites
 * /.well-known/<document>/modules/mcp to modules/mcp/.well-known/<document>,
 * so that RFC 8414's metadata URL for an issuer with a path is served. After
 * that internal rewrite REQUEST_URI still names the root path, which no route
 * under the base path matches; Apache's REDIRECT_URL holds the rewritten one.
 *
 * Only a .well-known path under the module is taken from it, and only when
 * the request's own path is outside the module.
 */
final class RootRewrite
{
    /** @param mixed $redirectUrl $_SERVER['REDIRECT_URL'], set by Apache's internal redirect */
    public static function apply(
        ServerRequestInterface $request,
        string $basePath,
        $redirectUrl
    ): ServerRequestInterface {
        $path = $request->getUri()->getPath();
        if (
            $basePath === ''
            || !is_string($redirectUrl)
            || strpos($path . '/', $basePath . '/') === 0
            || strpos($redirectUrl, $basePath . '/.well-known/') !== 0
        ) {
            return $request;
        }
        return $request->withUri($request->getUri()->withPath($redirectUrl));
    }
}
