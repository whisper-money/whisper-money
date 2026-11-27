<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\ForecastItem;
use App\Models\User;
use App\Services\Forecast\Backtest\BacktestObservation;
use App\Services\Forecast\Backtest\BacktestReport;
use App\Services\Forecast\Backtest\BacktestSeries;
use App\Services\Forecast\Backtest\BacktestWriteRefused;
use App\Services\Forecast\Backtest\ForecastBacktester;
use App\Services\Forecast\ForecastCalendar;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A user as steady as a clock: 2,000 of salary on the 25th and 500 of
 * groceries every month since January 2026, with the balance noted at each
 * month end (1,000 at the end of 2025, 1,500 more every month). Today is
 * 15 Nov 2026, so the last complete month is October. The forecast knows
 * exactly what will happen; a flat balance misses 1,500 a month.
 */
beforeEach(function () {
    $this->travelTo('2026-11-15 12:00:00');
    $this->user = User::factory()->create(['currency_code' => 'EUR', 'name' => 'Steady Saver']);
    $this->checking = backtestSteadyAccount($this->user, 'Steady checking');
});

function backtestSteadyAccount(User $user, string $name, string $firstMonth = '2026-01'): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    $salary = forecastIncomeCategory($user, name: 'Salary');
    $groceries = forecastExpenseCategory($user, name: 'Groceries');
    $balance = 100000;
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => ForecastCalendar::previousDay($firstMonth.'-01'), 'balance' => $balance]);

    for ($month = $firstMonth.'-01'; $month < '2026-11-01'; $month = ForecastCalendar::monthStart($month, 1)) {
        forecastCharge($account, ForecastCalendar::onDay($month, 25), 200000, 'PAYROLL', $salary);

        // Twenty small purchases in the months the cohort counts, one big one before.
        $purchases = $month >= '2026-08-01' ? 20 : 1;
        foreach (range(1, $purchases) as $index) {
            forecastCharge($account, ForecastCalendar::onDay($month, 1 + $index), -intdiv(50000, $purchases), 'SUPERMARKET', $groceries);
        }

        $balance += 150000;
        AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => ForecastCalendar::horizonEnd($month, 1), 'balance' => $balance]);
    }

    return $account;
}

/**
 * @return array<string, list<string>> The report's cells, by row title and months.
 */
function backtestRows(string $output): array
{
    $rows = [];

    foreach (explode("\n", $output) as $line) {
        $cells = array_map(trim(...), explode('|', trim($line, " |\r")));

        if (count($cells) >= 6 && is_numeric($cells[1])) {
            $rows[$cells[0].' @ '.$cells[1]] = array_slice($cells, 2);
        }
    }

    return $rows;
}

it('picks, for each horizon, the latest cut-offs that already have that many months of actuals after them', function () {
    expect(ForecastBacktester::cutoffs('2026-11-15', 1))->toBe(['2026-05-01' => [6], '2026-08-01' => [3], '2026-10-01' => [1]])
        ->and(ForecastBacktester::cutoffs('2026-11-15', 6))->toHaveCount(11)
        ->and(ForecastBacktester::cutoffs('2026-11-15', 6)['2026-05-01'])->toBe([1, 3, 6])
        ->and(array_key_first(ForecastBacktester::cutoffs('2026-11-15', 6)))->toBe('2025-12-01');
});

it('measures the forecast against what happened, next to a flat balance and the average net flow', function () {
    Artisan::call('forecast:backtest', ['--cutoffs' => 1, '--min-count' => 1, '--min-users' => 1]);
    $output = Artisan::output();
    $rows = backtestRows($output);

    expect($output)->toContain('Eligible users: 1 (1 active and well kept; left out: 0 who already set up a forecast, 0 with a budget created after the first cut-off)')
        ->toContain('Cut-offs: 3, from 2026-05-01 to 2026-10-01; actuals up to 2026-10-31')
        ->toContain('Baselines: a flat balance and the 3-month average net flow')
        // One cut-off per horizon: 1 Oct, 1 Aug and 1 May.
        // N, median |error|, p10 and p90 of actual / forecast, actual above the forecast, inside the band.
        ->and($rows[BacktestSeries::ACCOUNT.' @ 1'])->toBe(['1', '0.0 %', '1.00', '1.00', '0.0 %', '100.0 %'])
        ->and($rows[BacktestSeries::ACCOUNT.' @ 6'])->toBe(['1', '0.0 %', '1.00', '1.00', '0.0 %', '100.0 %'])
        // A flat balance misses 1,500 a month: 1,500 out of 16,000 after one month, 9,000 after six.
        ->and($rows[BacktestSeries::ACCOUNT_FLAT.' @ 1'])->toBe(['1', '9.4 %', '1.10', '1.10', '100.0 %', '–'])
        ->and($rows[BacktestSeries::ACCOUNT_FLAT.' @ 3'])->toBe(['1', '28.1 %', '1.39', '1.39', '100.0 %', '–'])
        ->and($rows[BacktestSeries::ACCOUNT_FLAT.' @ 6'])->toBe(['1', '56.3 %', '2.29', '2.29', '100.0 %', '–'])
        ->and($rows[BacktestSeries::ACCOUNT_NET_FLOW.' @ 6'])->toBe(['1', '0.0 %', '1.00', '1.00', '0.0 %', '–'])
        ->and($rows[BacktestSeries::TOTAL.' @ 3'])->toBe(['1', '0.0 %', '1.00', '1.00', '0.0 %', '100.0 %'])
        // N, median |error|, WAPE, p10, p90, actual above, inside the band, the band that would hold 80 %.
        ->and($rows[BacktestSeries::INCOME_CATEGORY.' @ 1'])->toBe(['1', '0.0 %', '0.0 %', '1.00', '1.00', '0.0 %', '100.0 %', '±0.0 %'])
        ->and($rows[BacktestSeries::EXPENSE_MONTH.' @ 6'])->toBe(['1', '0.0 %', '0.0 %', '1.00', '1.00', '0.0 %', '100.0 %', '±0.0 %']);
});

it('leaves out users with too little history, who set up a forecast or whose budgets came after the first cut-off', function () {
    $newcomer = User::factory()->create();
    backtestSteadyAccount($newcomer, 'Young account', '2026-08');
    $planner = User::factory()->create();
    $plannerAccount = backtestSteadyAccount($planner, 'Planned account');
    ForecastItem::factory()->amount(-10000)->create(['user_id' => $planner->id, 'account_id' => $plannerAccount->id]);
    $budgeter = User::factory()->create();
    backtestSteadyAccount($budgeter, 'Budgeted account');
    Budget::factory()->create(['user_id' => $budgeter->id, 'created_at' => '2026-09-01 10:00:00']);

    Artisan::call('forecast:backtest', ['--cutoffs' => 1, '--min-count' => 1, '--min-users' => 1]);

    expect(Artisan::output())->toContain('Eligible users: 1 (3 active and well kept; left out: 1 who already set up a forecast, 1 with a budget created after the first cut-off)');
});

it('prints aggregate figures only, nothing that tells a user apart', function () {
    Artisan::call('forecast:backtest', ['--cutoffs' => 1, '--min-count' => 1, '--min-users' => 1]);
    $output = Artisan::output();

    foreach ([$this->user->id, $this->user->email, $this->user->name, $this->checking->id, $this->checking->name, 'Salary', 'Groceries', 'PAYROLL', 'SUPERMARKET', '1600000', '16,000'] as $private) {
        expect($output)->not->toContain($private);
    }
});

it('writes nothing, and stops any statement that would', function () {
    $tables = ['forecast_items', 'forecast_snapshots', 'forecast_alerts', 'forecast_account_settings', 'forecast_estimate_adjustments', 'jobs', 'cache'];
    $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);

    Artisan::call('forecast:backtest', ['--cutoffs' => 1, '--min-count' => 1, '--min-users' => 1]);

    expect(array_map(fn (string $table): int => DB::table($table)->count(), $tables))->toBe($before);

    $this->mock(ForecastBacktester::class)->shouldReceive('observe')->andReturnUsing(function (): array {
        DB::table('forecast_dismissals')->insert(['id' => 'x']);

        return [];
    });

    expect(fn () => Artisan::call('forecast:backtest', ['--cutoffs' => 1, '--min-count' => 1, '--min-users' => 1]))->toThrow(BacktestWriteRefused::class);
});

it('prints only counts for a small cohort, leaves out rows with few observations and fails when a space could not be measured', function () {
    Artisan::call('forecast:backtest', ['--cutoffs' => 1]);
    expect(Artisan::output())->toContain('Too few eligible users')->not->toContain(BacktestSeries::ACCOUNT);

    Artisan::call('forecast:backtest', ['--cutoffs' => 6, '--min-count' => 3, '--min-users' => 1]);
    expect(backtestRows(Artisan::output()))->toHaveKey(BacktestSeries::ACCOUNT.' @ 1')->not->toHaveKey(BacktestSeries::ACCOUNT.' @ 6');

    $this->mock(ForecastBacktester::class)->shouldReceive('observe')->andThrow(new LogicException('a message about someone'));

    expect(Artisan::call('forecast:backtest', ['--cutoffs' => 1, '--min-users' => 1]))->toBe(1)
        ->and(Artisan::output())->toContain('Spaces measured: 0 of 1')->toContain('1 space(s) failed with LogicException')->not->toContain('a message about someone');
});

it('reports the median error, the spread of actual over forecast, the band and the weighted error', function () {
    $report = new BacktestReport;
    $report->add([
        new BacktestObservation(BacktestSeries::ACCOUNT, 1, 100000, 110000, 5000, 10000),
        new BacktestObservation(BacktestSeries::ACCOUNT, 1, 100000, 90000, 20000, 10000),
        new BacktestObservation(BacktestSeries::ACCOUNT, 1, 100000, 100000, 0, 10000),
        // Too small to divide by, either way: counted in the band and the weighted error only.
        new BacktestObservation(BacktestSeries::ACCOUNT, 1, 5000, 2000, 1000, 10000),
    ]);

    $row = $report->rows([BacktestSeries::ACCOUNT])[0];

    expect($row['count'])->toBe(4)
        ->and($row['medianError'])->toEqualWithDelta(1 / 11, 1e-9)
        ->and($row['p10'])->toEqualWithDelta(0.92, 1e-9)
        ->and($row['p90'])->toEqualWithDelta(1.08, 1e-9)
        ->and($row['insideBand'])->toBe(0.5)
        ->and($row['above'])->toBe(0.25)
        ->and($row['bandFor80'])->toEqualWithDelta(0.1, 1e-9)
        ->and($row['weightedError'])->toEqualWithDelta(23000 / 302000, 1e-9);
});
