<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Forecast\Backtest\BacktestCohort;
use App\Services\Forecast\Backtest\BacktestReport;
use App\Services\Forecast\Backtest\BacktestSeries;
use App\Services\Forecast\Backtest\BacktestWriteRefused;
use App\Services\Forecast\Backtest\ForecastBacktester;
use App\Services\Forecast\ForecastAccounts;
use App\Services\Forecast\ForecastCalendar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Measures how good the forecast is: for the most active, well-kept users
 * (see BacktestCohort), it builds the forecast as of the first day of past
 * months, from what was known then, and compares it with what happened 1, 3
 * and 6 months later, next to two baselines (a flat balance and the
 * 3-month average net flow). The forecast of v1 (detected recurring lines
 * plus the spend-only rest) cannot be rebuilt any more: its estimate was
 * replaced, and its matches were stored when a user reviewed the forecast,
 * not as of a cut-off.
 *
 * It only reads, so it can run against production (`--connection=prod`,
 * whose credentials are read only): every statement but a read is stopped
 * before it reaches the database, and the cache, queue and mail are kept in
 * memory. The statement check is a tripwire on top of the read-only
 * credentials, which are the real guarantee. It prints aggregate figures
 * only (a row with fewer than `--min-count` observations is left out), never
 * anything about one user,
 * and does not report failures to the error tracker (their messages could
 * carry a user's data): it counts them by exception class.
 */
class ForecastBacktest extends Command
{
    /**
     * Words that only a statement that writes or locks carries.
     */
    private const WRITES = '/\b(insert|update|delete|replace|merge|alter|drop|truncate|create|grant|revoke|lock|outfile|dumpfile)\b/i';

    protected $signature = 'forecast:backtest
        {--connection= : The database connection to read, e.g. prod; the default connection when left out}
        {--cutoffs=6 : How many cut-offs each horizon is measured over}
        {--min-categorised=90 : The share (%) of the last 3 months\' money a user must have in a category}
        {--min-users=20 : With fewer eligible users than this, only the counts are printed}
        {--min-count=10 : Rows with fewer observations than this are left out, so no figure stands for one user}';

    protected $description = 'Backtest the forecast against what really happened, over the most active users (aggregate figures only, read only)';

    public function handle(ForecastBacktester $backtester, ForecastAccounts $accounts): int
    {
        $this->readOnly((string) ($this->option('connection') ?: config('database.default')));

        $today = now()->toDateString();
        $actualsEnd = ForecastCalendar::previousDay(ForecastCalendar::monthStart($today));
        $cutoffs = ForecastBacktester::cutoffs($today, max((int) $this->option('cutoffs'), 1));
        $cohort = BacktestCohort::select($today, ((float) $this->option('min-categorised')) / 100, (string) array_key_first($cutoffs));

        $this->info("Eligible users: {$cohort->users->count()} ({$cohort->active} active and well kept; left out: {$cohort->withForecast} who already set up a forecast, {$cohort->withLaterBudgets} with a budget created after the first cut-off)");
        $this->info('Cut-offs: '.count($cutoffs).', from '.array_key_first($cutoffs).' to '.array_key_last($cutoffs).'; actuals up to '.$actualsEnd);

        if ($cohort->users->count() < max((int) $this->option('min-users'), 1)) {
            $this->warn('Too few eligible users to report figures that stand for no one.');

            return self::SUCCESS;
        }

        $report = new BacktestReport;
        $failures = [];
        $spaces = 0;

        $this->withProgressBar($cohort->users, function (User $user) use ($accounts, $backtester, $report, $cutoffs, $actualsEnd, &$failures, &$spaces): void {
            foreach ($accounts->spaceIds($user) as $spaceId) {
                $spaces++;

                try {
                    $report->add($backtester->observe($user, $spaceId, $cutoffs, $actualsEnd));
                } catch (BacktestWriteRefused $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $failures[$exception::class] = ($failures[$exception::class] ?? 0) + 1;
                }
            }
        });
        $this->newLine();

        $this->info('Spaces measured: '.($spaces - array_sum($failures))." of {$spaces}");
        $this->printReport($report, max((int) $this->option('min-count'), 1));

        foreach ($failures as $class => $count) {
            $this->warn("{$count} space(s) failed with {$class}: the figures above leave them out");
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Points every read at the connection and makes sure nothing is written:
     * a statement that is not a read throws before it runs, and the cache,
     * queue and mail stay in memory.
     */
    private function readOnly(string $connection): void
    {
        config(['database.default' => $connection]);
        DB::setDefaultConnection($connection);
        DB::connection($connection)->beforeExecuting(function (string $query): void {
            if (preg_match('/^\s*\(?\s*(select|with|show|describe|explain)\b/i', $query) !== 1 || preg_match(self::WRITES, $query) === 1) {
                throw BacktestWriteRefused::for($query);
            }
        });

        config(['queue.connections.forecast-backtest' => ['driver' => 'null']]);
        app('cache')->setDefaultDriver('array');
        app('queue')->setDefaultDriver('forecast-backtest');
        app('mail.manager')->setDefaultDriver('array');
    }

    private function printReport(BacktestReport $report, int $minCount): void
    {
        $this->newLine();
        $this->info('Baselines: a flat balance and the 3-month average net flow (the v1 forecast cannot be rebuilt as of a cut-off).');
        $this->info('The all-accounts total adds the accounts in the user\'s own currency only; its band is the sum of theirs.');
        $this->table(
            ['Closing balance', 'Months', 'N', 'Median |error|', 'p10 actual/forecast', 'p90 actual/forecast', 'Actual above', 'Inside band'],
            array_map(fn (array $row): array => [$row['series'], $row['horizon'], $row['count'], self::percent($row['medianError']), self::ratio($row['p10']), self::ratio($row['p90']), self::percent($row['above']), self::percent($row['insideBand'])], $report->rows(BacktestSeries::BALANCES, $minCount)),
        );
        $this->table(
            ['Income and spend', 'Month', 'N', 'Median |error|', 'WAPE', 'p10 actual/forecast', 'p90 actual/forecast', 'Actual above', 'Inside band', 'Band for 80 %'],
            array_map(fn (array $row): array => [$row['series'], $row['horizon'], $row['count'], self::percent($row['medianError']), self::percent($row['weightedError']), self::ratio($row['p10']), self::ratio($row['p90']), self::percent($row['above']), self::percent($row['insideBand']), $row['bandFor80'] === null ? '–' : '±'.self::percent($row['bandFor80'])], $report->rows(BacktestSeries::FLOWS, $minCount)),
        );
    }

    private static function percent(?float $value): string
    {
        return $value === null ? '–' : number_format($value * 100, 1).' %';
    }

    private static function ratio(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 2);
    }
}
