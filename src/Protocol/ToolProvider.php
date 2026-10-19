<?php

namespace FA\Mcp\Protocol;

/** The tools behind tools/list and tools/call, already scoped to the caller. */
interface ToolProvider
{
    /** @return array[] MCP tool descriptors in catalog order: name, title?, description, inputSchema, annotations */
    public function listTools(): array;

    /**
     * @return array MCP CallToolResult: content[], structuredContent?, isError?
     * @throws RpcError for unknown/hidden tool or invalid arguments (-32602)
     * @throws HttpStatusException for auth failures
     */
    public function callTool(string $name, array $arguments): array;
}
