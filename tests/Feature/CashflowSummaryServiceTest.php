<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashflowSummaryService;
use App\Services\PeriodComparator;
use Illuminate\Support\Facades\Http;

function record(User $user, CategoryType $type, int $amount, string $currency = 'EUR'): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create([
            'user_id' => $user->id,
            'currency_code' => $currency,
        ])->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => $type])->id,
        'amount' => $amount,
        'currency_code' => $currency,
        'transaction_date' => today(),
    ]);
}

function thisMonthsSummary(User $user): array
{
    $period = new PeriodComparator(now()->startOfMonth(), now()->endOfMonth());

    return app(CashflowSummaryService::class)
        ->forComparedPeriods($user->id, $user->currency_code, $period, $period->previous())['current'];
}

it('derives net and savings rate from income and expense', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    record($user, CategoryType::Income, 100000);
    record($user, CategoryType::Expense, -40000);

    expect(thisMonthsSummary($user))->toMatchArray([
        'income' => 100000,
        'expense' => 40000,
        'net' => 60000,
        'savings_rate' => 60.0,
    ]);
});

it('returns a negative net when expense exceeds income', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    record($user, CategoryType::Expense, -10000);

    expect(thisMonthsSummary($user))->toMatchArray([
        'income' => 0,
        'expense' => 10000,
        'net' => -10000,
        'savings_rate' => 0,
    ]);
});

it('avoids division by zero when income is zero', function () {
    $summary = thisMonthsSummary(User::factory()->create(['currency_code' => 'EUR']));

    expect($summary['savings_rate'])->toBe(0)
        ->and($summary['net'])->toBe(0);
});

it('rounds the savings rate to one decimal place', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    record($user, CategoryType::Income, 100000);
    record($user, CategoryType::Expense, -33333);

    // (100000 - 33333) / 100000 * 100 = 66.667 -> 66.7
    expect(thisMonthsSummary($user)['savings_rate'])->toBe(66.7);
});

it('counts a foreign-currency account in the user currency', function () {
    Http::fake();

    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => today()->toDateString(),
        'rates' => ['usd' => 1.25],
    ]);

    $user = User::factory()->create(['currency_code' => 'EUR']);
    record($user, CategoryType::Expense, -10000, 'USD');

    // 100 USD at 1.25 USD per EUR is 80 EUR, not 100.
    expect(thisMonthsSummary($user)['expense'])->toBe(8000);
});

it('counts an uncategorized outflow as an expense', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $transaction = record($user, CategoryType::Expense, -2100);
    Transaction::query()->whereKey($transaction->id)->update(['category_id' => null]);

    expect(thisMonthsSummary($user)['expense'])->toBe(2100);
});
