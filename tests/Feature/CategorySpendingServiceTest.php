<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashflowSummaryService;
use App\Services\CategorySpendingService;
use App\Services\PeriodComparator;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-15 12:00:00');
    fakeCurrencyApi();

    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->november = new PeriodComparator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function spendingCategory(User $user, CategoryType $type = CategoryType::Expense, array $attributes = []): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'type' => $type, ...$attributes]);
}

function spendingMovement(User $user, Account $account, ?Category $category, int $amount, string $date = '2026-11-10', array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'currency_code' => $account->currency_code,
        'transaction_date' => $date,
        ...$attributes,
    ]);
}

function novemberExpense(User $user): int
{
    $period = new PeriodComparator(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

    return app(CashflowSummaryService::class)
        ->forComparedPeriods($user->id, $user->currency_code, $period, $period->previous())['current']['expense'];
}

it('adds the categories and the uncategorized spending up to the cashflow expense', function () {
    $food = spendingCategory($this->user);
    $home = spendingCategory($this->user);
    $salary = spendingCategory($this->user, CategoryType::Income);
    $transfer = spendingCategory($this->user, CategoryType::Transfer);
    $dollars = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'USD']);
    $shared = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'ownership_percentage' => 50]);
    $archived = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'archived_at' => '2026-11-05 10:00:00']);
    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-11-10', 'rates' => ['usd' => 2.0]]);

    spendingMovement($this->user, $this->account, $food, -30000);
    spendingMovement($this->user, $this->account, $food, 5000);
    spendingMovement($this->user, $dollars, $home, -20000);
    spendingMovement($this->user, $shared, $home, -8000);
    spendingMovement($this->user, $this->account, null, -4000);
    spendingMovement($this->user, $this->account, null, -1000);
    spendingMovement($this->user, $this->account, null, 9000);
    spendingMovement($this->user, $this->account, $salary, 200000);
    spendingMovement($this->user, $this->account, $transfer, -50000);
    spendingMovement($this->user, $archived, $food, -7000, '2026-11-06');
    spendingMovement($this->user, $archived, null, -7000, '2026-11-06');

    $spending = app(CategorySpendingService::class)->comparedExpense($this->user, $this->november);

    expect(collect($spending['categories'])->mapWithKeys(fn (array $row): array => [$row['category_id'] => $row['amount']])->all())
        ->toBe([$food->id => 25000, $home->id => 14000])
        ->and($spending['uncategorized'])->toBe(5000)
        ->and($spending['total'])->toBe(44000)
        ->and($spending['total'])->toBe(novemberExpense($this->user))
        ->and(collect($spending['categories'])->pluck('total_amount')->unique()->all())->toBe([44000]);
});

it('leaves out onboarding estimates', function () {
    $rent = spendingCategory($this->user);
    $estimate = Label::factory()->estimate()->create(['user_id' => $this->user->id]);

    spendingMovement($this->user, $this->account, $rent, -90000)->labels()->attach($estimate->id);
    spendingMovement($this->user, $this->account, null, -5000)->labels()->attach($estimate->id);
    spendingMovement($this->user, $this->account, $rent, -1000);

    $service = app(CategorySpendingService::class);
    $spending = $service->comparedExpense($this->user, $this->november);

    expect($spending['categories'])->toHaveCount(1)
        ->and($spending['categories'][0]['amount'])->toBe(1000)
        ->and($spending['uncategorized'])->toBe(0)
        ->and($spending['total'])->toBe(1000)
        ->and($service->uncategorizedExpenseCount($this->user, $this->november))->toBe(0);
});

it('compares each category with the month before', function () {
    $food = spendingCategory($this->user);
    $travel = spendingCategory($this->user);

    spendingMovement($this->user, $this->account, $food, -3000);
    spendingMovement($this->user, $this->account, $food, -2000, '2026-10-31');
    spendingMovement($this->user, $this->account, $food, -9999, '2026-09-30');
    spendingMovement($this->user, $this->account, $travel, -1000);

    $rows = collect(app(CategorySpendingService::class)->comparedExpense($this->user, $this->november)['categories'])
        ->keyBy('category_id');

    expect($rows[$food->id]['previous_amount'])->toBe(2000)
        ->and($rows[$travel->id]['previous_amount'])->toBe(0);
});

it('ranks root categories by what they took and keeps only the top ones', function () {
    $small = spendingCategory($this->user);
    $big = spendingCategory($this->user);
    $child = Category::factory()->childOf($small)->create(['user_id' => $this->user->id]);
    $refunded = spendingCategory($this->user);

    spendingMovement($this->user, $this->account, $small, -1000);
    spendingMovement($this->user, $this->account, $child, -1500);
    spendingMovement($this->user, $this->account, $big, -6000);
    spendingMovement($this->user, $this->account, $refunded, 2000);

    $spending = app(CategorySpendingService::class)->comparedExpense($this->user, $this->november, limit: 1);

    expect(collect($spending['categories'])->pluck('category_id')->all())->toBe([$big->id])
        ->and($spending['total'])->toBe(6500);
});

it('drills into a parent without the uncategorized spending', function () {
    $food = spendingCategory($this->user);
    $groceries = Category::factory()->childOf($food)->create(['user_id' => $this->user->id]);

    spendingMovement($this->user, $this->account, $food, -1000);
    spendingMovement($this->user, $this->account, $groceries, -3000);
    spendingMovement($this->user, $this->account, null, -5000);

    $spending = app(CategorySpendingService::class)->comparedExpense($this->user, $this->november, $food->id);

    expect(collect($spending['categories'])->pluck('amount')->all())->toBe([3000, 1000])
        ->and($spending['uncategorized'])->toBe(0)
        ->and($spending['total'])->toBe(4000);
});

it('counts the uncategorized outflows of the period by the cashflow rules', function () {
    $archived = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'archived_at' => '2026-11-05 10:00:00']);

    spendingMovement($this->user, $this->account, null, -1000);
    spendingMovement($this->user, $this->account, null, -2000, '2026-11-30');
    spendingMovement($this->user, $this->account, null, 3000);
    spendingMovement($this->user, $this->account, null, -4000, '2026-10-31');
    spendingMovement($this->user, $this->account, spendingCategory($this->user), -5000);
    spendingMovement($this->user, $archived, null, -6000, '2026-11-06');
    spendingMovement(User::factory()->create(), $this->account, null, -7000);

    expect(app(CategorySpendingService::class)->uncategorizedExpenseCount($this->user, $this->november))->toBe(2);
});
