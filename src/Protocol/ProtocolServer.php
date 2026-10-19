<?php

namespace FA\Mcp\Protocol;

/** Turns one POSTed MCP message into the HTTP answer for it. */
interface ProtocolServer
{
    /** @param array<string,string> $headers lower-cased header name => value */
    public function handle(string $rawBody, array $headers, ToolProvider $tools): HttpOutcome;
}
