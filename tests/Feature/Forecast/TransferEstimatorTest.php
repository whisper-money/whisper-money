<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\ForecastPlan;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionInput;
use App\Services\Forecast\Data\TransferEstimate;
use App\Services\Forecast\ForecastAlertEvaluator;
use App\Services\Forecast\ForecastPlanBuilder;
use App\Services\Forecast\ForecastPlanRows;
use App\Services\Forecast\ForecastProjector;
use Laravel\Pennant\Feature;

const TRANSFER_START = '2026-11-10';

function transferPlan(User $user, ?array $accountIds = null): ForecastPlan
{
    return app(ForecastPlanBuilder::class)->build($user, $user->current_space_id, $accountIds, horizonMonths: 4, startDate: TRANSFER_START);
}

/**
 * The plan's transfer estimates, keyed by "from|to|month".
 *
 * @return array<string, TransferEstimate>
 */
function transferEstimates(ForecastPlan $plan): array
{
    return collect($plan->transferEstimates)
        ->keyBy(fn (TransferEstimate $estimate): string => $estimate->fromAccountId.'|'.$estimate->toAccountId.'|'.$estimate->month)
        ->all();
}

/**
 * Each account's projected months, by account id.
 *
 * @return array<string, list<array<string, int|string|null>>>
 */
function projectedMonths(ProjectionInput $input): array
{
    return collect(app(ForecastProjector::class)->project($input)['accounts'])->pluck('months', 'accountId')->all();
}

/**
 * The input without the estimated transfers, as T1 projected it.
 */
function withoutTransferEstimates(ProjectionInput $input): ProjectionInput
{
    return new ProjectionInput(
        $input->accounts,
        array_values(array_filter($input->occurrences, fn (Occurrence $occurrence): bool => ! str_starts_with($occurrence->itemId, 'transfer-estimate:'))),
        $input->estimates,
        $input->startDate,
        $input->horizonMonths,
    );
}

function moveBetween(Account $from, Account $to, string $date, int $amount): void
{
    forecastCharge($from, $date, -$amount, 'TRASPASO A '.$to->name);
    forecastCharge($to, $date, $amount, 'TRASPASO DE '.$from->name);
}

beforeEach(function () {
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
});

describe('salary in Payroll, part of it moved to Daily, where the card is spent', function () {
    beforeEach(function () {
        $this->payroll = Account::factory()->create(['type' => AccountType::Checking, 'name' => 'Payroll', 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
        $this->daily = Account::factory()->create(['type' => AccountType::Checking, 'name' => 'Daily', 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
        AccountBalance::factory()->create(['account_id' => $this->payroll->id, 'balance_date' => '2026-11-09', 'balance' => 300000]);
        AccountBalance::factory()->create(['account_id' => $this->daily->id, 'balance_date' => '2026-11-09', 'balance' => 300000]);
        $salary = forecastIncomeCategory($this->user, name: 'Salary');
        $groceries = forecastExpenseCategory($this->user, name: 'Groceries');
        $leisure = forecastExpenseCategory($this->user, name: 'Leisure');

        foreach (['2026-07-28', '2026-08-27', '2026-09-26', '2026-10-27'] as $date) {
            forecastCharge($this->payroll, $date, 250000, 'NOMINA', $salary);
        }
        foreach (['2026-08-01' => 150000, '2026-09-02' => 148000, '2026-10-01' => 152000, '2026-11-01' => 150000] as $date => $amount) {
            moveBetween($this->payroll, $this->daily, $date, $amount);
        }
        foreach (['2026-08', '2026-09', '2026-10'] as $month) {
            foreach ([4 => -30000, 11 => -35000, 18 => -25000, 25 => -30000] as $day => $amount) {
                forecastCharge($this->daily, sprintf('%s-%02d', $month, $day), $amount, 'MERCADONA', $groceries);
            }
            forecastCharge($this->daily, "{$month}-15", -20000, 'CINE', $leisure);
        }
        forecastCharge($this->daily, '2026-11-04', -30000, 'MERCADONA', $groceries);
    });

    it('projects the transfer every month at its median, on its usual day', function () {
        $estimates = transferEstimates(transferPlan($this->user));
        $key = fn (string $month): string => $this->payroll->id.'|'.$this->daily->id.'|'.$month;

        expect($estimates)->toHaveCount(4)
            ->and($estimates[$key('2026-12')])->amount->toBe(150000)->date->toBe('2026-12-01')
            ->and($estimates[$key('2027-02')])->amount->toBe(150000)->date->toBe('2027-02-01')
            ->and($estimates[$key('2026-11')])->amount->toBe(0)->moved->toBe(150000)->planned->toBe(150000)
            ->and($estimates[$key('2026-11')]->toOccurrence())->toBeNull();
    });

    it('keeps the transfer out of both accounts\' category medians', function () {
        $plan = transferPlan($this->user);
        $categoryEstimates = collect($plan->categoryEstimates)->filter(fn (CategoryEstimate $estimate): bool => $estimate->month === '2026-12');

        expect($categoryEstimates->filter(fn (CategoryEstimate $estimate): bool => $estimate->categoryId === null))->toBeEmpty()
            ->and($categoryEstimates->sum(fn (CategoryEstimate $estimate): int => $estimate->signedAmount()))->toBe(250000 - 140000);
    });

    it('keeps each account roughly flat, the total unchanged and Daily without an alert', function () {
        $input = transferPlan($this->user)->input;
        $after = projectedMonths($input);
        $before = projectedMonths(withoutTransferEstimates($input));
        $closes = fn (array $months): array => array_column($months, 'close');
        $total = fn (ProjectionInput $input): array => array_column(app(ForecastProjector::class)->project($input)['total'], 'close');
        $alert = fn (array $months): string => app(ForecastAlertEvaluator::class)->evaluate($months, 0)['level'];

        expect($closes($before[$this->daily->id]))->toBe([190000, 50000, -90000, -230000])
            ->and($closes($before[$this->payroll->id]))->toBe([550000, 800000, 1050000, 1300000])
            ->and($closes($after[$this->daily->id]))->toBe([190000, 200000, 210000, 220000])
            ->and($closes($after[$this->payroll->id]))->toBe([550000, 650000, 750000, 850000])
            ->and($total($input))->toBe($total(withoutTransferEstimates($input)))
            ->and($alert($before[$this->daily->id]))->toBe('strong')
            ->and($alert($after[$this->daily->id]))->toBe('ok');
    });
});

it('moves a contribution into an investment account, which only the projection covers', function () {
    $checking = Account::factory()->create(['type' => AccountType::Checking, 'name' => 'Checking', 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $fund = Account::factory()->create(['type' => AccountType::Investment, 'name' => 'Index fund', 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-11-09', 'balance' => 500000]);
    AccountBalance::factory()->create(['account_id' => $fund->id, 'balance_date' => '2026-11-09', 'balance' => 1000000]);
    foreach (['2026-08-15', '2026-09-15', '2026-10-15'] as $date) {
        moveBetween($checking, $fund, $date, 30000);
    }

    $plan = transferPlan($this->user);
    $months = projectedMonths($plan->input);

    expect(transferEstimates($plan)[$checking->id.'|'.$fund->id.'|2026-11'])->amount->toBe(30000)->date->toBe('2026-11-15')
        ->and($plan->categoryEstimates)->toBeEmpty()
        ->and(array_column($months[$checking->id], 'close'))->toBe([470000, 440000, 410000, 380000])
        ->and(array_column($months[$fund->id], 'close'))->toBe([1030000, 1060000, 1090000, 1120000]);
});

it('counts a transfer to a loan the forecast leaves out as money leaving, once', function () {
    $checking = Account::factory()->create(['type' => AccountType::Checking, 'name' => 'Checking', 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $loan = Account::factory()->loan()->create(['name' => 'Car loan', 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-11-09', 'balance' => 500000]);
    foreach (['2026-08-05', '2026-09-05', '2026-10-05', '2026-11-05'] as $date) {
        moveBetween($checking, $loan, $date, 40000);
    }

    $plan = transferPlan($this->user);
    $projection = app(ForecastProjector::class)->project($plan->input);

    expect(collect($plan->input->accounts)->pluck('id')->all())->toBe([$checking->id])
        ->and(transferEstimates($plan)[$checking->id.'|'.$loan->id.'|2026-12'])->amount->toBe(40000)->date->toBe('2026-12-05')
        ->and($plan->categoryEstimates)->toBeEmpty()
        ->and(array_column($projection['accounts'][0]['months'], 'close'))->toBe([500000, 460000, 420000, 380000])
        ->and(array_column($projection['total'], 'close'))->toBe([500000, 460000, 420000, 380000]);

    $all = app(ForecastPlanRows::class)->buildAll($this->user, $plan, ['2026-11', '2026-12'])['transfers'];

    expect($all)->toHaveCount(1)
        ->and($all[0])->toMatchArray(['direction' => 'out', 'counterpartyName' => 'Checking', 'name' => 'Car loan', 'amounts' => ['2026-11' => -40000, '2026-12' => -40000]]);
});

it('nets what comes back against what goes, and goes the way the median says', function () {
    $checking = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $savings = Account::factory()->create(['type' => AccountType::Savings, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    foreach (['2026-08', '2026-09', '2026-10'] as $month) {
        moveBetween($savings, $checking, "{$month}-03", 50000);
        moveBetween($checking, $savings, "{$month}-20", 10000);
    }

    $estimates = transferEstimates(transferPlan($this->user));

    expect($estimates)->toHaveKey($savings->id.'|'.$checking->id.'|2026-12')
        ->and($estimates[$savings->id.'|'.$checking->id.'|2026-12'])->amount->toBe(40000)->date->toBe('2026-12-03')
        ->and(collect($estimates)->every(fn (TransferEstimate $estimate): bool => $estimate->fromAccountId === $savings->id))->toBeTrue();
});

it('projects nothing for a pair whose median is zero, or a leg whose other side is not in the app', function () {
    $checking = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $savings = Account::factory()->create(['type' => AccountType::Savings, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    forecastCharge($savings, '2026-07-31', 500, 'INTERESES');
    moveBetween($checking, $savings, '2026-09-10', 200000);
    foreach (['2026-08-01', '2026-09-01', '2026-10-01'] as $date) {
        forecastCharge($checking, $date, -25000, 'TRASPASO A OTRO BANCO', forecastCategory($this->user, CategoryType::Transfer));
    }

    expect(transferPlan($this->user)->transferEstimates)->toBeEmpty();
});

it('lets a manual transfer line project on its own, leaving what paid it out of the median', function () {
    $checking = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $savings = Account::factory()->create(['type' => AccountType::Savings, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $line = ForecastItem::factory()->transfer($savings)->amount(-20000)->create(['user_id' => $this->user->id, 'account_id' => $checking->id, 'anchor_date' => '2026-08-02']);
    foreach (['2026-08-02', '2026-09-02', '2026-10-02'] as $date) {
        $out = forecastCharge($checking, $date, -20000, 'AHORRO');
        $in = forecastCharge($savings, $date, 20000, 'AHORRO');
        ForecastMatch::factory()->create(['forecast_item_id' => $line->id, 'transaction_id' => $out->id, 'occurrence_date' => $date]);
        ForecastMatch::factory()->create(['forecast_item_id' => $line->id, 'transaction_id' => $in->id, 'occurrence_date' => $date]);
    }

    $plan = transferPlan($this->user);

    expect($plan->transferEstimates)->toBeEmpty()
        ->and(collect($plan->input->occurrences)->pluck('itemId')->unique()->all())->toBe([$line->id]);
});

it('shows the usual transfer in the plan of both accounts, read-only', function () {
    $payroll = Account::factory()->create(['type' => AccountType::Checking, 'name' => 'Payroll', 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $daily = Account::factory()->create(['type' => AccountType::Checking, 'name' => 'Daily', 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    foreach (['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'] as $date) {
        moveBetween($payroll, $daily, $date, 150000);
    }
    $plan = transferPlan($this->user);
    $months = ['2026-11', '2026-12', '2027-01'];
    $rows = app(ForecastPlanRows::class);

    $in = $rows->build($this->user, $plan, $daily, $months)['transfers'];
    $out = $rows->build($this->user, $plan, $payroll, $months)['transfers'];
    $all = $rows->buildAll($this->user, $plan, $months)['transfers'];

    expect($in)->toHaveCount(1)
        ->and($in[0])->toMatchArray([
            'kind' => 'transfer',
            'source' => 'estimate',
            'direction' => 'in',
            'counterpartyName' => 'Payroll',
            'anchorDate' => '2026-12-01',
            'amounts' => ['2026-11' => 150000, '2026-12' => 150000, '2027-01' => 150000],
            'current' => ['planned' => 150000, 'real' => 150000, 'date' => '2026-11-10', 'status' => 'matched'],
            'occurrenceDates' => [],
        ])
        ->and($out[0])->toMatchArray(['direction' => 'out', 'counterpartyName' => 'Daily', 'amounts' => ['2026-11' => -150000, '2026-12' => -150000, '2027-01' => -150000]])
        ->and($all[0])->toMatchArray(['direction' => 'in', 'counterpartyName' => 'Payroll', 'name' => 'Daily'])
        ->and($all[0]['definition'])->toMatchArray(['accountId' => $payroll->id, 'destinationAccountId' => $daily->id, 'versions' => []]);
});

it('shows the usual transfer on the page with the flag on, and nothing changes with it off', function (bool $flag) {
    $this->travelTo('2026-11-10 12:00:00');
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    $flag ? Feature::for($user)->activate(Forecast::class) : Feature::for($user)->deactivate(Forecast::class);
    $payroll = Account::factory()->create(['type' => AccountType::Checking, 'name' => 'Payroll', 'user_id' => $user->id, 'currency_code' => 'EUR']);
    $daily = Account::factory()->create(['type' => AccountType::Checking, 'name' => 'Daily', 'user_id' => $user->id, 'currency_code' => 'EUR']);
    foreach (['2026-08-01', '2026-09-01', '2026-10-01'] as $date) {
        moveBetween($payroll, $daily, $date, 150000);
    }

    $response = $this->actingAs($user)->get(route('forecast.index', ['account' => $daily->id]));

    if (! $flag) {
        $response->assertNotFound();

        return;
    }

    $transfers = $response->viewData('page')['props']['forecast']['plan']['transfers'];

    expect($transfers)->toHaveCount(1)
        ->and($transfers[0])->toMatchArray(['source' => 'estimate', 'direction' => 'in', 'counterpartyName' => 'Payroll'])
        ->and($transfers[0]['amounts']['2026-12'])->toBe(150000);
})->with(['flag on' => true, 'flag off' => false]);
