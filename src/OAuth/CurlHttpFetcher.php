<?php

namespace FA\Mcp\OAuth;

/**
 * HttpFetcher over curl. CURLOPT_RESOLVE pins the connection to the address
 * CimdFetcher checked; the proxy is switched off because a proxy would do
 * its own resolving. Not exercised by the unit suite (it needs the network).
 */
final class CurlHttpFetcher implements HttpFetcher
{
    public function get(string $url, string $pinnedIp, int $timeoutSeconds, int $maxBytes): array
    {
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            throw new \RuntimeException('The URL has no host.');
        }
        $address = strpos($pinnedIp, ':') !== false ? '[' . $pinnedIp . ']' : $pinnedIp;

        $body = '';
        $tooBig = false;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':443:' . $address],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooBig, $maxBytes) {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0; // anything but strlen($chunk) aborts the transfer
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($tooBig) {
            throw new \RuntimeException(sprintf('The response is larger than %d bytes.', $maxBytes));
        }
        if ($ok === false) {
            throw new \RuntimeException('Fetch failed: ' . $error);
        }
        return ['status' => $status, 'body' => $body];
    }
}
