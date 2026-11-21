<?php

return [
    // The private sidecar runs the extended upstream MCP project.
    'mcp_url' => env('KASRA_MCP_URL', 'http://kasra-mcp:8000/mcp'),
    'mcp_token' => env('KASRA_MCP_TOKEN'),
    'envelope_key' => env('KASRA_MCP_ENVELOPE_KEY'),
];
