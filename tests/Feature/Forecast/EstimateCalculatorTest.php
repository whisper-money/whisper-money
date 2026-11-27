<?php

use App\Enums\AccountType;
use App\Enums\ForecastEstimateBasis;
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
 * The estimates of the plan starting on ESTIMATE_START, keyed by
 * "account|category|month" (an empty category for "Other spending").
 *
 * @return array<string, CategoryEstimate>
 */
function estimatesFor(User $user, int $horizonMonths = 4): array
{
    return collect(app(ForecastPlanBuilder::class)->build($user, $user->current_space_id, horizonMonths: $horizonMonths, startDate: ESTIMATE_START)->categoryEstimates)
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

it('leaves out what paid a forecast line, was a one-off or moved between the user\'s accounts', function () {
    $matched = forecastCharge($this->account, '2026-10-01', -90000, 'ALQUILER', $this->groceries);
    ForecastMatch::factory()->create(['transaction_id' => $matched->id]);
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
