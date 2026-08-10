<?php

namespace App\Console\Commands;

use App\Models\McpToolCall;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

class McpUsageStatsCommand extends Command
{
    protected $signature = 'stats:mcp-usage {--days=30 : Days of history to report on} {--top=20 : Users listed in the per-user table}';

    protected $description = 'Show MCP usage: calls per tool, calls per user and daily totals';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = Carbon::now()->subDays($days - 1)->startOfDay();

        $total = $this->callsSince($since)->count();

        if ($total === 0) {
            $this->warn("No MCP tool calls in the last {$days} days.");

            return self::SUCCESS;
        }

        $users = $this->callsSince($since)->distinct()->count('user_id');

        $this->newLine();
        $this->line("<options=bold>MCP usage — last {$days} days</> (since {$since->toDateString()})");
        $this->line("  Calls: <fg=cyan>{$total}</>   Users: <fg=cyan>{$users}</>");

        $this->renderTools($since, $total);
        $this->renderUsers($since);
        $this->renderDays($since);

        return self::SUCCESS;
    }

    /**
     * Every call in the window, as a plain query builder: the report only ever
     * reads aggregates, so it has no use for hydrated models.
     */
    private function callsSince(Carbon $since): Builder
    {
        return McpToolCall::query()->toBase()->where('mcp_tool_calls.created_at', '>=', $since);
    }

    private function renderTools(Carbon $since, int $total): void
    {
        $rows = $this->callsSince($since)
            ->selectRaw('tool, count(*) as calls, count(distinct user_id) as users')
            ->groupBy('tool')
            ->orderByDesc('calls')
            ->get();

        $this->newLine();
        $this->line('<options=bold>By tool</>');
        $this->table(
            ['Tool', 'Calls', '%', 'Users'],
            $rows->map(fn (object $row): array => [
                $row->tool,
                $row->calls,
                sprintf('%.1f%%', $row->calls / $total * 100),
                $row->users,
            ])->all()
        );
    }

    /**
     * Joined rather than eager-loaded so users who deleted their account still
     * show up: `user:delete` soft-deletes, so the cascade never fires and the
     * relation (with its `deleted_at is null` scope) would come back empty.
     */
    private function renderUsers(Carbon $since): void
    {
        $top = max(1, (int) $this->option('top'));

        $rows = $this->callsSince($since)
            ->join('users', 'users.id', '=', 'mcp_tool_calls.user_id')
            ->selectRaw('users.email, users.deleted_at, count(*) as calls, count(distinct tool) as tools, max(mcp_tool_calls.created_at) as last_call')
            ->groupBy('users.id', 'users.email', 'users.deleted_at')
            ->orderByDesc('calls')
            ->limit($top)
            ->get();

        $this->newLine();
        $this->line("<options=bold>By user</> (top {$top})");
        $this->table(
            ['User', 'Calls', 'Tools', 'Last call'],
            $rows->map(fn (object $row): array => [
                $row->email.($row->deleted_at === null ? '' : ' (deleted)'),
                $row->calls,
                $row->tools,
                (string) $row->last_call,
            ])->all()
        );
    }

    private function renderDays(Carbon $since): void
    {
        $rows = $this->callsSince($since)
            ->selectRaw('date(mcp_tool_calls.created_at) as day, count(*) as calls, count(distinct user_id) as users')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $this->newLine();
        $this->line('<options=bold>By day</>');
        $this->table(
            ['Day', 'Calls', 'Users'],
            $rows->map(fn (object $row): array => [
                (string) $row->day,
                $row->calls,
                $row->users,
            ])->all()
        );
    }
}
