<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\WriteTool;
use Laravel\Mcp\Server\Tool;

/**
 * The AI Connector section of the landing page tells visitors how many tools
 * the MCP server exposes, split into the ones that read data and the ones that
 * change it. Those two figures are constants in welcome.tsx, so nothing keeps
 * them honest as tools are added or removed except this test.
 */
it('claims on the landing page the number of tools the server exposes', function () {
    /** @var array<int, class-string<Tool>> $tools */
    $tools = (new ReflectionClass(WhisperMoneyServer::class))->getDefaultProperties()['tools'];

    $writes = array_filter($tools, fn (string $tool): bool => is_subclass_of($tool, WriteTool::class));

    $source = file_get_contents(dirname(__DIR__, 3).'/resources/js/pages/welcome.tsx');

    expect($source)
        ->toContain('const MCP_READ_TOOL_COUNT = '.(count($tools) - count($writes)).';')
        ->toContain('const MCP_WRITE_TOOL_COUNT = '.count($writes).';');
});
