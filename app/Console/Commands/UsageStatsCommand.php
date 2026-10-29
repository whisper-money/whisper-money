<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Shared report behind the `stats:*-usage` commands: one append-only row per
 * use (an MCP tool call, an API request), broken down by what was used, by
 * user and by day.
 */
abstract class UsageStatsCommand extends Command
{
    /**
     * The surface reported on, as printed in the headings ("MCP", "API").
     */
    abstract protected function surface(): string;

    /**
     * What one row is called, singular and lowercase ("call", "request").
     */
    abstract protected function unit(): string;

    /**
     * The column naming what was used ("tool", "endpoint").
     */
    abstract protected function dimension(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * Extra breakdowns a surface adds after the per-dimension table.
     */
    protected function renderExtra(Carbon $since, int $total): void {}

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = Carbon::now()->subDays($days - 1)->startOfDay();

        $total = $this->usageSince($since)->count();

        $window = $days.' '.Str::plural('day', $days);

        if ($total === 0) {
            $this->warn("No {$this->surface()} {$this->usageName()} in the last {$window}.");

            return self::SUCCESS;
        }

        $users = $this->usageSince($since)->distinct()->count('user_id');

        $this->newLine();
        $this->line("<options=bold>{$this->surface()} usage — last {$window}</> (since {$since->toDateString()})");
        $this->line('  '.Str::ucfirst($this->units()).": <fg=cyan>{$total}</>   Users: <fg=cyan>{$users}</>");

        $this->renderDimension($since, $total);
        $this->renderExtra($since, $total);
        $this->renderUsers($since);
        $this->renderDays($since);

        return self::SUCCESS;
    }

    /**
     * Every row in the window, as a plain query builder: the report only ever
     * reads aggregates, so it has no use for hydrated models.
     *
     * The shared demo/press accounts are left out: journalists driving the press
     * account are demo traffic, and counting them would overstate real usage.
     */
    protected function usageSince(Carbon $since): Builder
    {
        return $this->model()::query()
            ->toBase()
            ->where($this->usageTable().'.created_at', '>=', $since)
            ->whereNotIn('user_id', fn (Builder $query) => $query
                ->select('id')
                ->from('users')
                ->whereIn('email', User::sharedAccountEmails()));
    }

    /**
     * A table of rows grouped by `$columns`, each with its share of the total.
     * A row is named after its non-null column values, joined with `:`.
     *
     * @param  non-empty-list<string>  $columns
     */
    protected function renderShares(string $heading, string $label, array $columns, Carbon $since, int $total): void
    {
        $rows = $this->usageSince($since)
            ->select($columns)
            ->selectRaw('count(*) as calls, count(distinct user_id) as users')
            ->groupBy($columns)
            ->orderByDesc('calls')
            ->get();

        $this->newLine();
        $this->line("<options=bold>{$heading}</>");
        $this->table(
            [$label, Str::ucfirst($this->units()), '%', 'Users'],
            $rows->map(fn (object $row): array => [
                implode(':', array_filter(array_map(fn (string $column): mixed => $row->{$column}, $columns), fn (mixed $value): bool => $value !== null)),
                $row->calls,
                sprintf('%.1f%%', $row->calls / $total * 100),
                $row->users,
            ])->all()
        );
    }

    /**
     * How the rows are named in the "nothing to report" line.
     */
    protected function usageName(): string
    {
        return $this->units();
    }

    protected function units(): string
    {
        return Str::plural($this->unit());
    }

    private function usageTable(): string
    {
        return (new ($this->model()))->getTable();
    }

    private function renderDimension(Carbon $since, int $total): void
    {
        $dimension = $this->dimension();

        $this->renderShares('By '.$dimension, Str::ucfirst($dimension), [$dimension], $since, $total);
    }

    /**
     * Joined rather than eager-loaded so users who deleted their account still
     * show up: `user:delete` soft-deletes, so the cascade never fires and the
     * relation (with its `deleted_at is null` scope) would come back empty.
     */
    private function renderUsers(Carbon $since): void
    {
        $top = max(1, (int) $this->option('top'));
        $table = $this->usageTable();
        $dimension = $this->dimension();

        $rows = $this->usageSince($since)
            ->join('users', 'users.id', '=', "{$table}.user_id")
            ->selectRaw("users.email, users.deleted_at, count(*) as calls, count(distinct {$dimension}) as used, max({$table}.created_at) as last_used")
            ->groupBy('users.id', 'users.email', 'users.deleted_at')
            ->orderByDesc('calls')
            ->limit($top)
            ->get();

        $this->newLine();
        $this->line("<options=bold>By user</> (top {$top})");
        $this->table(
            ['User', Str::ucfirst($this->units()), Str::ucfirst(Str::plural($dimension)), 'Last '.$this->unit()],
            $rows->map(fn (object $row): array => [
                $row->email.($row->deleted_at === null ? '' : ' (deleted)'),
                $row->calls,
                $row->used,
                (string) $row->last_used,
            ])->all()
        );
    }

    private function renderDays(Carbon $since): void
    {
        $table = $this->usageTable();

        $rows = $this->usageSince($since)
            ->selectRaw("date({$table}.created_at) as day, count(*) as calls, count(distinct user_id) as users")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $this->newLine();
        $this->line('<options=bold>By day</>');
        $this->table(
            ['Day', Str::ucfirst($this->units()), 'Users'],
            $rows->map(fn (object $row): array => [
                (string) $row->day,
                $row->calls,
                $row->users,
            ])->all()
        );
    }
}
