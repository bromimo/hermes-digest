<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StreamableHttpServerTransport;

$server = Server::make()
    ->withServerInfo('mcp-news', '1.0.0')
    ->build();

// Scan src/ for #[McpTool] attributes (discovers DigestTools).
$server->discover(basePath: __DIR__ . '/..', scanDirs: ['src']);

$transport = new StreamableHttpServerTransport(
    host: getenv('MCP_HOST') ?: '0.0.0.0', // inside container; not published to host
    port: (int) (getenv('MCP_PORT') ?: 8000),
    mcpPath: 'mcp',                          // serves /mcp (real param name in v3.3.0)
    enableJsonResponse: false,               // SSE streaming (StreamableHTTP default)
    stateless: false,
);

fwrite(STDERR, "mcp-news listening on " . (getenv('MCP_HOST') ?: '0.0.0.0') . ":" . ((int) (getenv('MCP_PORT') ?: 8000)) . "/mcp\n");

$server->listen($transport); // blocking; runs the ReactPHP event loop