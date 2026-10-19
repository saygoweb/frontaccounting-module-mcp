<?php

namespace FA\Mcp\Http;

/**
 * Keeps everything PHP and FrontAccounting print away from the client (spec §7
 * "Output"): a port of the GraphQL module's class of the same name.
 *
 * index.php starts it right after the autoloader and ends it just before Slim's
 * emitter sends the response: what was printed in between, and every header set
 * by then, is logged and dropped. If the request ends first — exit, die, or a
 * fatal error, all of which FrontAccounting code can reach — the shutdown function
 * drops the output instead and answers the fixed JSON-RPC 500 (-32603): nothing
 * has been sent, so it still can.
 */
final class OutputCapture
{
    public const BODY = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';

    /** How much of the discarded output is written to the log. */
    public const LOGGED_BYTES = 2048;

    /** The output-buffer level below the capture's own buffer; null when not capturing. */
    private static ?int $level = null;

    private static bool $shutdownRegistered = false;

    public static function start(): void
    {
        if (self::$level !== null) {
            return;
        }
        self::$level = ob_get_level();
        ob_start();
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'onShutdown']);
            self::$shutdownRegistered = true;
        }
    }

    /**
     * Drops everything printed since start(), and every header set so far, and
     * returns the output (which is also logged). Call it immediately before the
     * response is emitted.
     */
    public static function end(): string
    {
        if (self::$level === null) {
            return '';
        }
        $captured = self::drain(self::$level);
        self::$level = null;
        self::forgetHeaders();
        if ($captured !== '') {
            error_log('mcp: discarded ' . self::describe($captured));
        }

        return $captured;
    }

    /**
     * After the response is emitted, whatever a later shutdown function prints
     * would be appended to the body. A buffer that discards it closes that gap.
     */
    public static function afterEmit(): void
    {
        ob_start(static function (string $buffer): string {
            if ($buffer !== '') {
                error_log('mcp: discarded, after the response, ' . self::describe($buffer));
            }

            return '';
        });
    }

    /**
     * Registered by start(); public only so PHP can call it. Does nothing once
     * end() has run.
     */
    public static function onShutdown(): void
    {
        if (self::$level === null) {
            return;
        }
        $captured = self::drain(self::$level);
        self::$level = null;
        $error = error_get_last();
        error_log(
            'mcp: the request ended before its response (exit, die or a fatal error)'
            . ($error !== null
                ? '; last error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
                : '')
            . '; discarded ' . self::describe($captured)
        );
        if (!headers_sent()) {
            self::forgetHeaders();
            // header_remove() clears ordinary headers but leaves a status line
            // already set via header('HTTP/1.1 ...') in place, and
            // http_response_code() alone does not override that line (verified
            // against PHP's built-in server); sending the status line directly
            // does.
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json; charset=utf-8');
        }
        echo self::BODY;
        self::afterEmit();
    }

    /**
     * Closes every buffer above $level, innermost first, and returns what they
     * held in the order it was printed. A handler buffer's callback still runs as
     * it closes (and may call header(): forgetHeaders() follows), but its return
     * value is discarded with the rest.
     */
    private static function drain(int $level): string
    {
        $parts = [];
        while (ob_get_level() > $level) {
            $parts[] = (string) ob_get_contents();
            if (!@ob_end_clean()) {
                // A buffer opened without PHP_OUTPUT_HANDLER_REMOVABLE cannot be
                // closed: empty it and stop, rather than loop forever.
                @ob_clean();
                break;
            }
        }

        return implode('', array_reverse($parts));
    }

    private static function forgetHeaders(): void
    {
        if (!headers_sent()) {
            header_remove();
        }
    }

    private static function describe(string $output): string
    {
        $excerpt = substr($output, 0, self::LOGGED_BYTES);

        return strlen($output) . ' bytes of output: ' . $excerpt
            . (strlen($output) > strlen($excerpt) ? ' [...]' : '');
    }
}
