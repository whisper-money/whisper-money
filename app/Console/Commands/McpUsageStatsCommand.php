<?php

namespace App\Console\Commands;

use App\Models\McpToolCall;

class McpUsageStatsCommand extends UsageStatsCommand
{
    protected $signature = 'stats:mcp-usage {--days=30 : Days of history to report on} {--top=20 : Users listed in the per-user table}';

    protected $description = 'Show MCP usage: calls per tool, calls per user and daily totals';

    protected function surface(): string
    {
        return 'MCP';
    }

    protected function unit(): string
    {
        return 'call';
    }

    protected function dimension(): string
    {
        return 'tool';
    }

    protected function usageName(): string
    {
        return 'tool calls';
    }

    protected function model(): string
    {
        return McpToolCall::class;
    }
}
