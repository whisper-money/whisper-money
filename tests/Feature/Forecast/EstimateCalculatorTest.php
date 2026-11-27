<?php

use App\Enums\AccountType;
use App\Enums\ForecastEstimateBasis;
use App\Enums\ForecastEstimateKind;
use App\Enums\ForecastFrequency;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Label;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\ForecastPlanBuilder;

const ESTIMATE_START = '2026-11-10';

/**
 * The spend estimates of the plan starting on ESTIMATE_START, keyed by
 * "account|category|month" (an empty category for "Other spending").
 *
 * @return array<string, CategoryEstimate>
 */
function estimatesFor(User $user, int $horizonMonths = 4): array
{
    return estimatesOfKind($user, ForecastEstimateKind::Expense, $horizonMonths);
}

/**
 * Like estimatesFor(), for the income estimates ("Other income" under an
 * empty category).
 *
 * @return array<string, CategoryEstimate>
 */
function incomeEstimatesFor(User $user, int $horizonMonths = 4): array
{
    return estimatesOfKind($user, ForecastEstimateKind::Income, $horizonMonths);
}

/**
 * @return array<string, CategoryEstimate>
 */
function estimatesOfKind(User $user, ForecastEstimateKind $kind, int $horizonMonths): array
{
    return collect(app(ForecastPlanBuilder::class)->build($user, $user->current_space_id, horizonMonths: $horizonMonths, startDate: ESTIMATE_START)->categoryEstimates)
        ->filter(fn (CategoryEstimate $estimate): bool => $estimate->kind === $kind)
        ->keyBy(fn (CategoryEstimate $estimate): string => $estimate->accountId.'|'.$estimate->categoryId.'|'.$estimate->month)
        ->all();
}

function fittingBudget(User $user, int $limit, Category ...$categories): Budget
{
    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id, 'period_start_day' => 1]);
    $budget->categories()->sync(array_map(fn (Category $category): string => $category->id, $categories));
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'allocated_amount' => $limit]);

    return $budget;
}

beforeEach(function () {
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->food = forecastExpenseCategory($this->user);
    $this->groceries = forecastExpenseCategory($this->user, $this->food);
    $this->restaurants = forecastExpenseCategory($this->user, $this->food);

    forecastCharge($this->account, '2026-08-12', -30000, 'MERCADONA', $this->groceries);
    forecastCharge($this->account, '2026-09-12', -35000, 'MERCADONA', $this->groceries);
    forecastCharge($this->account, '2026-09-20', -15000, 'LA TAGLIATELLA', $this->restaurants);
    forecastCharge($this->account, '2026-10-12', -40000, 'MERCADONA', $this->groceries);
    forecastCharge($this->account, '2026-11-03', -15000, 'MERCADONA', $this->groceries);
});

it('estimates each parent category with the median of the last three complete months', function () {
    $estimates = estimatesFor($this->user);
    $row = fn (string $month): ?CategoryEstimate => $estimates[$this->account->id.'|'.$this->food->id.'|'.$month] ?? null;

    expect($row('2026-12'))->amount->toBe(40000)->basis->toBe(ForecastEstimateBasis::Median)
        ->and($row('2027-02'))->amount->toBe(40000)
        ->and($row('2026-11'))->amount->toBe(25000);
});

it('leaves out what paid a line the forecast still projects, was a one-off or moved between the user\'s accounts', function () {
    $matched = forecastCharge($this->account, '2026-10-01', -90000, 'ALQUILER', $this->groceries);
    $yearly = ForecastItem::factory()->frequency(ForecastFrequency::Yearly)->create(['user_id' => $this->user->id, 'account_id' => $this->account->id]);
    ForecastMatch::factory()->create(['forecast_item_id' => $yearly->id, 'transaction_id' => $matched->id]);
    $oneOff = forecastCharge($this->account, '2026-09-25', -200000, 'BODA', $this->restaurants);
    ForecastExcludedTransaction::factory()->create(['transaction_id' => $oneOff->id]);
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    foreach (['2026-08-02', '2026-09-02', '2026-10-02'] as $date) {
        forecastCharge($this->account, $date, -60000, 'PAGO TARJETA');
        forecastCharge($card, $date, 60000, 'PAGO RECIBIDO');
    }

    $estimates = estimatesFor($this->user);

    expect($estimates[$this->account->id.'|'.$this->food->id.'|2026-12']->amount)->toBe(40000)
        ->and($estimates)->not->toHaveKey($this->account->id.'||2026-12');
});

it('gathers uncategorized spend and categories under 30 a month into other spending', function () {
    $hobbies = forecastExpenseCategory($this->user);
    foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
        forecastCharge($this->account, $date, -1000, 'KIOSKO', $hobbies);
        forecastCharge($this->account, $date, -2000, 'BIZUM ENVIADO');
    }

    $estimates = estimatesFor($this->user);

    expect($estimates[$this->account->id.'||2026-12'])->amount->toBe(3000)->categoryId->toBeNull()
        ->and($estimates)->not->toHaveKey($this->account->id.'|'.$hobbies->id.'|2026-12');
});

it('reads the 30 a month in each account\'s own currency', function () {
    $yen = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'JPY']);
    $small = forecastExpenseCategory($this->user);
    $large = forecastExpenseCategory($this->user);
    foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
        forecastCharge($yen, $date, -20, 'KONBINI', $small);
        forecastCharge($yen, $date, -5000, 'RAMEN', $large);
    }

    $estimates = estimatesFor($this->user);

    expect($estimates[$yen->id.'|'.$large->id.'|2026-12']->amount)->toBe(5000)
        ->and($estimates[$yen->id.'||2026-12']->amount)->toBe(20);
});

it('stands on a budget that fits the parent, less its concrete lines', function () {
    fittingBudget($this->user, 60000, $this->groceries, $this->restaurants);
    ForecastItem::factory()->amount(-20000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'frequency' => ForecastFrequency::Bimonthly,
        'anchor_date' => '2026-11-20',
    ]);

    $estimates = estimatesFor($this->user);
    $row = fn (string $month): CategoryEstimate => $estimates[$this->account->id.'|'.$this->food->id.'|'.$month];

    expect($row('2026-12'))->amount->toBe(60000)->basis->toBe(ForecastEstimateBasis::Budget)
        ->and($row('2027-01')->amount)->toBe(40000)
        ->and($row('2026-11')->amount)->toBe(25000);
});

it('ignores a budget that follows labels or spans several parents', function (string $shape) {
    $budget = $shape === 'label'
        ? fittingBudget($this->user, 60000, $this->groceries)
        : fittingBudget($this->user, 60000, $this->groceries, forecastExpenseCategory($this->user));
    if ($shape === 'label') {
        $budget->labels()->sync([Label::factory()->create(['user_id' => $this->user->id])->id]);
    }

    expect(estimatesFor($this->user)[$this->account->id.'|'.$this->food->id.'|2026-12'])
        ->amount->toBe(40000)->basis->toBe(ForecastEstimateBasis::Median);
})->with(['label', 'several parents']);

it('splits a budget over the accounts in the user\'s currency by what each spent', function () {
    $second = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $dollars = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'USD']);
    forecastCharge($second, '2026-09-14', -30000, 'CARREFOUR', $this->groceries);
    foreach (['2026-08-14', '2026-09-14', '2026-10-14'] as $date) {
        forecastCharge($dollars, $date, -30000, 'WHOLE FOODS', $this->groceries);
    }
    fittingBudget($this->user, 80000, $this->groceries);

    $estimates = estimatesFor($this->user);

    expect($estimates[$this->account->id.'|'.$this->food->id.'|2026-12']->amount)->toBe(64000)
        ->and($estimates[$second->id.'|'.$this->food->id.'|2026-12']->amount)->toBe(16000)
        ->and($estimates[$dollars->id.'|'.$this->food->id.'|2026-12'])->basis->toBe(ForecastEstimateBasis::Median)->amount->toBe(30000);
});

it('takes the user\'s adjustment for the months it covers', function () {
    ForecastEstimateAdjustment::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-01-31',
        'amount' => 10000,
    ]);
    ForecastEstimateAdjustment::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'starts_on' => '2026-12-01',
        'amount' => 0,
    ]);

    $estimates = estimatesFor($this->user);

    expect($estimates[$this->account->id.'|'.$this->food->id.'|2027-01'])->amount->toBe(10000)->basis->toBe(ForecastEstimateBasis::Adjustment)
        ->and($estimates[$this->account->id.'|'.$this->food->id.'|2027-02'])->amount->toBe(40000)->basis->toBe(ForecastEstimateBasis::Median)
        ->and($estimates[$this->account->id.'||2026-12'])->amount->toBe(0)->basis->toBe(ForecastEstimateBasis::Adjustment);
});

it('weighs a shared account by the user\'s share of it', function () {
    $shared = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR', 'ownership_percentage' => 50]);
    foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
        forecastCharge($shared, $date, -10000, 'LUZ', $this->groceries);
    }

    expect(estimatesFor($this->user)[$shared->id.'|'.$this->food->id.'|2026-12']->amount)->toBe(5000);
});

it('reads a young account from its first month only', function () {
    $young = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    forecastCharge($young, '2026-10-08', -40000, 'LIDL', $this->groceries);

    expect(estimatesFor($this->user)[$young->id.'|'.$this->food->id.'|2026-12']->amount)->toBe(40000);
});

it('takes a parent\'s concrete lines off its budget on any account the budget covers', function () {
    $second = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    fittingBudget($this->user, 60000, $this->groceries);
    ForecastItem::factory()->amount(-20000)->create([
        'user_id' => $this->user->id,
        'account_id' => $second->id,
        'category_id' => $this->groceries->id,
        'frequency' => ForecastFrequency::Monthly,
        'anchor_date' => '2026-11-20',
    ]);

    expect(estimatesFor($this->user)[$this->account->id.'|'.$this->food->id.'|2026-12']->amount)->toBe(40000);
});

it('counts what paid a detected recurring line, which no longer projects', function () {
    $detected = ForecastItem::factory()->detected()->amount(-90000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'anchor_date' => '2026-08-01',
    ]);
    foreach (['2026-08-01', '2026-09-01', '2026-10-01'] as $date) {
        ForecastMatch::factory()->create([
            'forecast_item_id' => $detected->id,
            'occurrence_date' => $date,
            'transaction_id' => forecastCharge($this->account, $date, -90000, 'ALQUILER', $this->groceries)->id,
        ]);
    }

    expect(estimatesFor($this->user)[$this->account->id.'|'.$this->food->id.'|2026-12']->amount)->toBe(130000);
});

it('estimates each income category with its median, landing on its usual day', function () {
    $work = forecastIncomeCategory($this->user);
    $salary = forecastIncomeCategory($this->user, $work);
    forecastCharge($this->account, '2026-08-27', 250000, 'NOMINA AGOSTO', $salary);
    forecastCharge($this->account, '2026-09-25', 260000, 'TRANSFERENCIA NOMINA', $salary);
    forecastCharge($this->account, '2026-10-29', 250000, 'ABONO NOMINA OCT', $salary);

    $estimates = incomeEstimatesFor($this->user);
    $december = $estimates[$this->account->id.'|'.$work->id.'|2026-12'];

    expect($december)->amount->toBe(250000)->basis->toBe(ForecastEstimateBasis::Median)->day->toBe(27)->profile->toBeNull()
        ->and($december->signedAmount())->toBe(250000)
        ->and($estimates[$this->account->id.'|'.$work->id.'|2026-11'])->amount->toBe(250000)->spent->toBe(0)
        ->and(estimatesFor($this->user))->not->toHaveKey($this->account->id.'|'.$work->id.'|2026-12');
});

it('does not add again the income the start month already received', function () {
    $salary = forecastIncomeCategory($this->user);
    foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
        forecastCharge($this->account, $date, 200000, 'NOMINA', $salary);
    }
    forecastCharge($this->account, '2026-11-05', 180000, 'NOMINA', $salary);

    $november = incomeEstimatesFor($this->user)[$this->account->id.'|'.$salary->id.'|2026-11'];

    expect($november)->amount->toBe(20000)->planned->toBe(200000)->spent->toBe(180000);
});

it('keeps other income apart from other spending', function () {
    $tips = forecastIncomeCategory($this->user);
    foreach (['2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
        forecastCharge($this->account, $date, 1000, 'PROPINAS', $tips);
        forecastCharge($this->account, $date, 1500, 'BIZUM RECIBIDO');
        forecastCharge($this->account, $date, -4000, 'BIZUM ENVIADO');
    }

    expect(incomeEstimatesFor($this->user)[$this->account->id.'||2026-12'])->amount->toBe(2500)->categoryId->toBeNull()
        ->and(incomeEstimatesFor($this->user))->not->toHaveKey($this->account->id.'|'.$tips->id.'|2026-12')
        ->and(estimatesFor($this->user)[$this->account->id.'||2026-12'])->amount->toBe(4000);
});

it('takes an adjustment without a category on the side it was made on', function () {
    ForecastEstimateAdjustment::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'kind' => ForecastEstimateKind::Income,
        'starts_on' => '2027-01-01',
        'amount' => 50000,
    ]);

    expect(incomeEstimatesFor($this->user)[$this->account->id.'||2027-01'])->amount->toBe(50000)->basis->toBe(ForecastEstimateBasis::Adjustment)
        ->and(estimatesFor($this->user))->not->toHaveKey($this->account->id.'||2027-01');
});

it('takes the user\'s adjustment of an income category as income', function () {
    $salary = forecastIncomeCategory($this->user);
    foreach (['2026-08-27', '2026-09-27', '2026-10-27'] as $date) {
        forecastCharge($this->account, $date, 200000, 'NOMINA', $salary);
    }
    ForecastEstimateAdjustment::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $salary->id,
        'starts_on' => '2027-01-01',
        'amount' => 230000,
    ]);

    $estimates = incomeEstimatesFor($this->user);

    expect($estimates[$this->account->id.'|'.$salary->id.'|2027-01'])->amount->toBe(230000)->basis->toBe(ForecastEstimateBasis::Adjustment)->day->toBe(27)
        ->and($estimates[$this->account->id.'|'.$salary->id.'|2026-12'])->amount->toBe(200000);
});

it('spreads a spend category by the days it was spent on', function () {
    $profile = estimatesFor($this->user)[$this->account->id.'|'.$this->food->id.'|2026-12']->profile;

    // 30,000 + 35,000 + 40,000 on the 12th and 15,000 on the 20th.
    expect($profile)->toHaveCount(31)
        ->and(array_sum($profile))->toBe(10000)
        ->and($profile[11])->toBe(8750)
        ->and($profile[19])->toBe(1250);
});
