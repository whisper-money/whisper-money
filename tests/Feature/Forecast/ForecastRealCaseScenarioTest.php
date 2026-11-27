<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\ForecastEstimateKind;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;
use App\Services\Forecast\Data\MonthlyEstimate;
use App\Services\Forecast\Data\TransferEstimate;
use App\Services\Forecast\ForecastPlanBuilder;
use Laravel\Pennant\Feature;

/**
 * The account that made v2 necessary, end to end (synthetic figures): the
 * salary lands in "Salary" on a Payroll checking account between the 22nd and
 * the 29th, with a description and a payer that change from one payment to
 * the next, and the holder's own name as creditor on half of them; one July
 * an extra payment arrives under another category. Around the 1st about 60 %
 * of it moves to a Daily checking account, where the card is spent; Payroll
 * also pays the mortgage. The loan and the house are accounts of their own,
 * and three investments only have a balance history: one growing, one
 * falling and one with three points. Today is 10 Sep 2026, so the three
 * months the estimates learn from are June to August, July's extra included.
 */
beforeEach(function () {
    $this->travelTo('2026-09-10 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR', 'name' => 'Jane Doe']);
    Feature::for($this->user)->activate(Forecast::class);

    $this->payroll = realCaseAccount($this->user, 'Payroll', AccountType::Checking, ['2026-09-09' => 350000]);
    $this->daily = realCaseAccount($this->user, 'Daily', AccountType::Checking, ['2026-09-09' => 300000]);
    $this->loan = realCaseAccount($this->user, 'Mortgage loan', AccountType::Loan, realCaseMonthEnds(-15_000_000, 60_000));
    $this->house = realCaseAccount($this->user, 'House', AccountType::RealEstate, ['2026-01-15' => 30_000_000]);
    $this->growing = realCaseAccount($this->user, 'Index fund', AccountType::Investment, realCaseMonthEnds(1_000_000, 30_000));
    $this->falling = realCaseAccount($this->user, 'Crypto', AccountType::Investment, realCaseMonthEnds(800_000, -20_000));
    $this->short = realCaseAccount($this->user, 'Pension plan', AccountType::Investment, ['2026-06-30' => 500_000, '2026-07-31' => 510_000, '2026-08-31' => 520_000]);

    $this->salary = forecastIncomeCategory($this->user, name: 'Salary');
    $bonus = forecastIncomeCategory($this->user, name: 'Phantom Shares');
    $mortgage = forecastExpenseCategory($this->user, name: 'Mortgage');
    $transfers = forecastCategory($this->user, CategoryType::Transfer, name: 'Transfers');
    $spend = ['Groceries' => [5 => 20000, 12 => 20000, 19 => 20000, 26 => 20000], 'Leisure' => [8 => 10000, 16 => 10000, 23 => 10000], 'Fuel' => [10 => 15000, 24 => 15000]];
    $spendCategories = array_map(fn (string $name) => forecastExpenseCategory($this->user, name: $name), array_combine(array_keys($spend), array_keys($spend)));

    $salaries = [
        '2026-03' => ['2026-03-25', 284000, 'NOMINA MARZO ACME SL', 'ACME SL'],
        '2026-04' => ['2026-04-23', 286000, 'ABONO NÓMINA ABRIL', 'Acme Spain, S.L.'],
        '2026-05' => ['2026-05-26', 285000, 'NOMINA MAYO ACME SL', 'ACME SL'],
        '2026-06' => ['2026-06-24', 286500, 'TRANSF. ACME SPAIN S.L. SALARIO', 'ACME SPAIN S.L.'],
        '2026-07' => ['2026-07-29', 290000, 'ABONO NOMINA 07/26', null],
        '2026-08' => ['2026-08-27', 287000, 'TRANSFERENCIA DE ACME SL CONCEPTO NOMINA 08', 'ACME'],
    ];
    $moved = ['2026-03' => [2, 171000], '2026-04' => [1, 173000], '2026-05' => [1, 171000], '2026-06' => [2, 172000], '2026-07' => [1, 173000], '2026-08' => [3, 172000], '2026-09' => [2, 172000]];
    $index = 0;

    foreach ($salaries as [$date, $amount, $description, $payer]) {
        forecastCharge($this->payroll, $date, $amount, $description, $this->salary, ['debtor_name' => $payer, 'creditor_name' => $index++ % 2 === 0 ? 'JANE DOE' : null]);
    }
    forecastCharge($this->payroll, '2026-07-15', 600000, 'PHANTOM SHARES PAYOUT', $bonus, ['debtor_name' => 'ACME HOLDING', 'creditor_name' => 'JANE DOE']);

    foreach ($moved as $month => [$day, $amount]) {
        $date = sprintf('%s-%02d', $month, $day);
        forecastCharge($this->payroll, $date, -$amount, 'TRANSFERENCIA A DAILY', $transfers);
        forecastCharge($this->daily, $date, $amount, 'TRANSFERENCIA DESDE PAYROLL', $transfers);
        forecastCharge($this->payroll, "{$month}-05", -90000, 'PRESTAMO HIPOTECARIO '.$month, $mortgage);

        foreach ($spend as $name => $days) {
            foreach ($days as $spendDay => $spent) {
                if (sprintf('%s-%02d', $month, $spendDay) < '2026-09-10') {
                    forecastCharge($this->daily, sprintf('%s-%02d', $month, $spendDay), -$spent, strtoupper($name).' CARD '.$spendDay, $spendCategories[$name]);
                }
            }
        }
    }
});

/**
 * @param  array<string, int>  $balances  By date.
 */
function realCaseAccount(User $user, string $name, AccountType $type, array $balances): Account
{
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => $name, 'type' => $type, 'currency_code' => 'EUR']);

    foreach ($balances as $date => $balance) {
        AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => $date, 'balance' => $balance]);
    }

    return $account;
}

/**
 * The balance at the end of each of the 12 complete months before today,
 * moving by the same amount every month.
 *
 * @return array<string, int>
 */
function realCaseMonthEnds(int $first, int $monthly): array
{
    $balances = [];

    foreach (range(0, 11) as $index) {
        $balances[now()->startOfMonth()->subMonthsNoOverflow(12 - $index)->endOfMonth()->toDateString()] = $first + $monthly * $index;
    }

    return $balances;
}

/**
 * @return array<string, mixed>
 */
function realCaseForecast(User $user, string $account): array
{
    return test()->actingAs($user)->get(route('forecast.index', ['account' => $account, 'horizon' => 12]))->assertOk()->viewData('page')['props']['forecast'];
}

it('projects the salary every month on the 27th, whatever its description, payer or the July extra', function () {
    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id);
    $income = collect($plan->input->estimates)->filter(fn (MonthlyEstimate $estimate): bool => $estimate->kind === ForecastEstimateKind::Income);

    expect($income)->toHaveCount(12)
        ->and($income->every(fn (MonthlyEstimate $estimate): bool => $estimate->accountId === $this->payroll->id && $estimate->categoryId === $this->salary->id && $estimate->amount === 287000 && $estimate->day === 27))->toBeTrue();
});

it('projects the move from Payroll to Daily every month, once this month is done', function () {
    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id);
    $moves = collect($plan->transferEstimates);

    expect($moves->every(fn (TransferEstimate $move): bool => $move->fromAccountId === $this->payroll->id && $move->toAccountId === $this->daily->id))->toBeTrue()
        ->and($moves->firstWhere('month', '2026-09'))->amount->toBe(0)->moved->toBe(172000)
        ->and($moves->where('amount', 172000)->pluck('month')->all())->toBe(array_map(fn (int $offset): string => now()->startOfMonth()->addMonthsNoOverflow($offset)->format('Y-m'), range(1, 11)));
});

it('leaves the loan and the house out, and follows each investment along its trend', function () {
    $forecast = realCaseForecast($this->user, 'all');
    $tabs = collect(realCaseForecast($this->user, $this->payroll->id)['accounts'])->keyBy('id');
    $closes = fn (Account $account): array => array_column(collect($forecast['accountForecasts'])->firstWhere('id', $account->id)['projection'], 'close');

    expect($tabs->keys()->sort()->values()->all())->toBe(collect([$this->payroll, $this->daily, $this->growing, $this->falling, $this->short])->pluck('id')->sort()->values()->all())
        ->and(collect($forecast['accountChoices'])->whereIn('id', [$this->loan->id, $this->house->id])->pluck('included')->all())->toBe([false, false])
        ->and($tabs[$this->growing->id]['trend']['monthly'])->toBe(30000)
        ->and($tabs[$this->falling->id]['trend']['monthly'])->toBe(-20000)
        ->and($tabs[$this->short->id]['trend'])->toMatchArray(['monthly' => null, 'points' => 3])
        ->and($closes($this->growing)[11])->toBe(1_330_000 + 11 * 30000)
        ->and($closes($this->falling)[11])->toBe(580_000 - 11 * 20000)
        ->and(array_unique($closes($this->short)))->toBe([520_000]);
});

it('raises no alert anywhere, and the all-accounts total is the sum of its accounts', function () {
    $all = realCaseForecast($this->user, 'all');
    $accountCloses = array_map(fn (array $account): array => array_column($account['projection'], 'close'), $all['accountForecasts']);

    expect($all['alert']['level'])->toBe('ok')
        ->and(realCaseForecast($this->user, $this->payroll->id)['alert']['level'])->toBe('ok')
        ->and(realCaseForecast($this->user, $this->daily->id)['alert']['level'])->toBe('ok')
        ->and(collect(realCaseForecast($this->user, $this->daily->id)['accounts'])->pluck('hasAlert')->unique()->all())->toBe([false])
        ->and(array_column($all['projection'], 'close'))->toBe(array_map(fn (int $index): int => array_sum(array_column($accountCloses, $index)), array_keys($all['projection'])));
});

it('shows none of it with the Forecast flag off', function () {
    Feature::for($this->user)->deactivate(Forecast::class);

    $this->actingAs($this->user)->get(route('forecast.index', ['account' => 'all']))->assertNotFound();
});
