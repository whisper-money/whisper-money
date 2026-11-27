<?php

use App\Enums\AccountType;
use App\Enums\ForecastEstimateKind;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\MonthlyEstimate;
use App\Services\Forecast\ForecastPlanBuilder;
use App\Services\Forecast\ForecastProjector;

/**
 * The account the v1 forecast got wrong: a salary in "Salary" whose
 * description and payer change from one payment to the next, paid between
 * the 22nd and the 29th; an extra payment one July in another category; and
 * groceries spread over the month. Nothing was confirmed in a review, so
 * there is no forecast line at all: v1 detected no salary, estimated no
 * income and drew a balance that only went down.
 */
beforeEach(function () {
    $this->travelTo('2026-10-10 12:00:00');
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->checking = Account::factory()->create(['user_id' => $this->user->id, 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $this->checking->id, 'balance_date' => '2026-10-09', 'balance' => 120000]);

    $this->salary = forecastIncomeCategory($this->user, name: 'Salary');
    $this->bonus = forecastIncomeCategory($this->user, name: 'Phantom Shares');
    $this->groceries = forecastExpenseCategory($this->user, name: 'Groceries');

    $payments = [
        ['2026-04-24', 284000, 'NOMINA ABRIL ACME SL', 'ACME SL'],
        ['2026-05-28', 286500, 'TRANSF. ACME SPAIN S.L. SALARIO', 'ACME SPAIN S.L.'],
        ['2026-06-26', 285000, 'ABONO NOMINA 06/26', null],
        ['2026-07-29', 290000, 'NOMINA JULIO', 'ACME SPAIN SL'],
        ['2026-08-27', 287000, 'TRANSFERENCIA DE ACME SL CONCEPTO NOMINA 08', 'ACME'],
        ['2026-09-22', 286000, 'ABONO NÓMINA SEPTIEMBRE', 'Acme Spain, S.L.'],
    ];
    foreach ($payments as [$date, $amount, $description, $payer]) {
        forecastCharge($this->checking, $date, $amount, $description, $this->salary, ['debtor_name' => $payer, 'creditor_name' => 'JANE DOE']);
    }
    forecastCharge($this->checking, '2026-07-15', 600000, 'PHANTOM SHARES PAYOUT', $this->bonus, ['debtor_name' => 'ACME HOLDING']);

    foreach (['2026-07', '2026-08', '2026-09', '2026-10'] as $month) {
        foreach ([3 => -6200, 9 => -4800, 16 => -7100, 23 => -5300, 30 => -3900] as $day => $amount) {
            if ("{$month}-".sprintf('%02d', $day) < '2026-10-10') {
                forecastCharge($this->checking, "{$month}-".sprintf('%02d', $day), $amount, 'MERCADONA '.$day, $this->groceries);
            }
        }
    }
});

it('projects the salary every month around the 27th at the median of the last three months', function () {
    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id);
    $salary = collect($plan->input->estimates)->filter(fn (MonthlyEstimate $estimate): bool => $estimate->categoryId === $this->salary->id);

    expect($plan->items)->toBeEmpty()
        ->and($salary)->toHaveCount(12)
        ->and($salary->every(fn (MonthlyEstimate $estimate): bool => $estimate->kind === ForecastEstimateKind::Income && $estimate->amount === 287000 && $estimate->day === 27))->toBeTrue();
});

it('leaves the one-off July payment out, since its median is nothing', function () {
    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id);
    $otherIncome = collect($plan->categoryEstimates)->filter(fn (CategoryEstimate $estimate): bool => $estimate->isIncome() && $estimate->categoryId !== $this->salary->id);

    expect($otherIncome)->toBeEmpty();
});

it('spreads the groceries over the days they are bought and lets the balance grow', function () {
    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id);
    $groceries = collect($plan->categoryEstimates)->first(fn (CategoryEstimate $estimate): bool => $estimate->categoryId === $this->groceries->id && $estimate->month === '2026-11');
    $months = app(ForecastProjector::class)->project($plan->input)['accounts'][0]['months'];
    $closes = array_column($months, 'close');

    expect($groceries)->amount->toBe(27300)
        ->and(array_keys(array_filter($groceries->profile)))->toBe([2, 8, 15, 22, 29])
        ->and($months[1])->toMatchArray(['month' => '2026-11', 'income' => 287000, 'expenses' => 27300, 'minDate' => '2026-11-23'])
        ->and($closes)->toBe(array_map(fn (int $index): int => $closes[0] + $index * (287000 - 27300), array_keys($closes)))
        ->and(min(array_column($months, 'min')))->toBeGreaterThan(0);
});
