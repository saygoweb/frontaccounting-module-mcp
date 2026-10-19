<?php

namespace FA\Mcp\OAuth;

/** The one outbound request the module makes: a CIMD document. */
interface HttpFetcher
{
    /**
     * GET over HTTPS, no redirects, connecting only to $pinnedIp.
     *
     * @return array{status:int, body:string}
     * @throws \RuntimeException on transport error, timeout, or body > $maxBytes
     */
    public function get(string $url, string $pinnedIp, int $timeoutSeconds, int $maxBytes): array;
}
