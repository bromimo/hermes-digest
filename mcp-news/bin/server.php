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

$host = getenv('MCP_HOST') ?: '0.0.0.0';
$port = (int) (getenv('MCP_PORT') ?: 8000);

$transport = new StreamableHttpServerTransport(
    host: $host,
    port: $port,
    mcpPath: 'mcp',
    enableJsonResponse: false, // false = SSE streaming (JSON-mode выключен; в либе по умолчанию true)
    stateless: false,
);

fwrite(STDERR, "mcp-news listening on {$host}:{$port}/mcp\n");

$server->listen($transport); // blocking; runs the ReactPHP event loop
