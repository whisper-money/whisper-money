<?php

namespace App\Console\Commands;

use App\Models\McpToolCall;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class McpUsageStatsCommand extends Command
{
    protected $signature = 'stats:mcp-usage {--days=30 : Days of history to report on} {--top=20 : Users listed in the per-user table}';

    protected $description = 'Show MCP usage: calls per tool, calls per user and daily totals';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = Carbon::now()->subDays($days)->startOfDay();

        $total = $this->scope($since)->count();

        if ($total === 0) {
            $this->warn("No MCP tool calls in the last {$days} days.");

            return self::SUCCESS;
        }

        $users = $this->scope($since)->distinct()->count('user_id');

        $this->newLine();
        $this->line("<options=bold>MCP usage — last {$days} days</> (since {$since->toDateString()})");
        $this->line("  Calls: <fg=cyan>{$total}</>   Users: <fg=cyan>{$users}</>");

        $this->renderTools($since, $total);
        $this->renderUsers($since);
        $this->renderDays($since);

        return self::SUCCESS;
    }

    /** @return Builder<McpToolCall> */
    private function scope(Carbon $since): Builder
    {
        return McpToolCall::query()->where('created_at', '>=', $since);
    }

    private function renderTools(Carbon $since, int $total): void
    {
        $rows = $this->scope($since)
            ->selectRaw('tool, count(*) as calls, count(distinct user_id) as users')
            ->groupBy('tool')
            ->orderByDesc('calls')
            ->get();

        $this->newLine();
        $this->line('<options=bold>By tool</>');
        $this->table(
            ['Tool', 'Calls', '%', 'Users'],
            $rows->map(fn (McpToolCall $row): array => [
                $row->tool,
                $row->getAttribute('calls'),
                sprintf('%.1f%%', $row->getAttribute('calls') / $total * 100),
                $row->getAttribute('users'),
            ])->all()
        );
    }

    private function renderUsers(Carbon $since): void
    {
        $top = max(1, (int) $this->option('top'));

        $rows = $this->scope($since)
            ->selectRaw('user_id, count(*) as calls, count(distinct tool) as tools, max(created_at) as last_call')
            ->groupBy('user_id')
            ->orderByDesc('calls')
            ->limit($top)
            ->with('user:id,email')
            ->get();

        $this->newLine();
        $this->line("<options=bold>By user</> (top {$top})");
        $this->table(
            ['User', 'Calls', 'Tools', 'Last call'],
            $rows->map(fn (McpToolCall $row): array => [
                $row->user->email,
                $row->getAttribute('calls'),
                $row->getAttribute('tools'),
                (string) $row->getAttribute('last_call'),
            ])->all()
        );
    }

    private function renderDays(Carbon $since): void
    {
        $rows = $this->scope($since)
            ->selectRaw('date(created_at) as day, count(*) as calls, count(distinct user_id) as users')
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $this->newLine();
        $this->line('<options=bold>By day</>');
        $this->table(
            ['Day', 'Calls', 'Users'],
            $rows->map(fn (McpToolCall $row): array => [
                (string) $row->getAttribute('day'),
                $row->getAttribute('calls'),
                $row->getAttribute('users'),
            ])->all()
        );
    }
}
