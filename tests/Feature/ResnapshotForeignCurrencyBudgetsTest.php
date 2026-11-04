<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Budget rows written before snapshots were converted hold the transaction
 * amount as booked. 1 EUR buys 2 USD on the rated day, so the 100 USD expense
 * stored as 100 should weigh 50 EUR.
 */

beforeEach(function () {
    // No rate is ever fetched: the provider answers empty, as on a missing day.
    Http::fake();
    Mail::fake();

    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

    ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-10-10', 'rates' => ['usd' => 2.0]]);

    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    [$this->period, $this->usdExpense, $this->eurExpense] = staleForeignCurrencyBudget($this->user);
});

/**
 * An EUR user's October budget holding, as the bug stored them, a 100 USD
 * expense left unconverted and a 30 EUR one already right.
 *
 * @return array{0: BudgetPeriod, 1: Transaction, 2: Transaction}
 */
function staleForeignCurrencyBudget(User $user): array
{
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => Budget::factory()->monthly()->forCategories($category)->create(['user_id' => $user->id])->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'allocated_amount' => 100000,
    ]);

    $expenses = collect(['USD' => -10000, 'EUR' => -3000])->map(function (int $amount, string $currency) use ($user, $account, $category, $period): Transaction {
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'currency_code' => $currency,
            'amount' => $amount,
            'transaction_date' => '2026-10-10',
        ]);

        BudgetTransaction::query()->updateOrCreate(
            ['budget_period_id' => $period->id, 'transaction_id' => $transaction->id],
            ['amount' => -$amount],
        );

        return $transaction;
    });

    return [$period, $expenses['USD'], $expenses['EUR']];
}

function storedBudgetAmount(Transaction $transaction, BudgetPeriod $period): ?int
{
    return BudgetTransaction::query()
        ->where('transaction_id', $transaction->id)
        ->where('budget_period_id', $period->id)
        ->value('amount');
}

test('a dry run reports what would change and writes nothing', function () {
    $this->artisan('budgets:resnapshot-foreign-currency')
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('1 budget row(s) across 1 user(s) and 1 budget period(s) would be rewritten. Spending delta: -50.00 EUR.')
        ->assertSuccessful();

    expect(storedBudgetAmount($this->usdExpense, $this->period))->toBe(10000);
});

test('--force converts the foreign-currency row and leaves the same-currency one alone', function () {
    $eurRowUpdatedAt = BudgetTransaction::query()->where('transaction_id', $this->eurExpense->id)->value('updated_at');
    $this->travel(1)->hour();

    $this->artisan('budgets:resnapshot-foreign-currency', ['--force' => true])
        ->expectsOutputToContain('1 budget row(s) across 1 user(s) and 1 budget period(s) were rewritten. Spending delta: -50.00 EUR.')
        ->assertSuccessful();

    expect(storedBudgetAmount($this->usdExpense, $this->period))->toBe(5000)
        ->and(storedBudgetAmount($this->eurExpense, $this->period))->toBe(3000)
        ->and(BudgetTransaction::query()->where('transaction_id', $this->eurExpense->id)->value('updated_at'))->toEqual($eurRowUpdatedAt);
});

test('a second run finds nothing left to change', function () {
    $this->artisan('budgets:resnapshot-foreign-currency', ['--force' => true])->assertSuccessful();

    $this->artisan('budgets:resnapshot-foreign-currency', ['--force' => true])
        ->expectsOutputToContain('0 budget row(s) were rewritten')
        ->assertSuccessful();

    expect(storedBudgetAmount($this->usdExpense, $this->period))->toBe(5000);
});

test('--user only repairs that user\'s rows', function () {
    $otherUser = User::factory()->create(['currency_code' => 'EUR']);
    [$otherPeriod, $otherUsdExpense] = staleForeignCurrencyBudget($otherUser);

    $this->artisan('budgets:resnapshot-foreign-currency', ['--force' => true, '--user' => $this->user->id])
        ->expectsOutputToContain('1 budget row(s) across 1 user(s)')
        ->assertSuccessful();

    expect(storedBudgetAmount($this->usdExpense, $this->period))->toBe(5000)
        ->and(storedBudgetAmount($otherUsdExpense, $otherPeriod))->toBe(10000);
});

test('an unknown --user fails without writing', function (string $userId) {
    $this->artisan('budgets:resnapshot-foreign-currency', ['--force' => true, '--user' => $userId])->assertFailed();

    expect(storedBudgetAmount($this->usdExpense, $this->period))->toBe(10000);
})->with(['not-a-uuid', '00000000-0000-4000-8000-000000000000']);

test('the repair sends no email and resets the limit alert flags of the periods whose total changed', function () {
    $this->period->update(['close_to_limit_notified' => true, 'over_limit_notified' => true]);
    $untouched = BudgetPeriod::factory()->create([
        'budget_id' => $this->period->budget_id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'over_limit_notified' => true,
    ]);

    $this->artisan('budgets:resnapshot-foreign-currency', ['--force' => true])
        ->expectsOutputToContain('Limit alert flags were reset on 1 budget period(s).')
        ->assertSuccessful();

    Mail::assertNothingOutgoing();
    expect($this->period->fresh())
        ->close_to_limit_notified->toBeFalse()
        ->over_limit_notified->toBeFalse()
        ->and($untouched->fresh()->over_limit_notified)->toBeTrue();
});

test('a dry run reports the periods whose flags would be reset and keeps them', function () {
    $this->period->update(['over_limit_notified' => true]);

    $this->artisan('budgets:resnapshot-foreign-currency')
        ->expectsOutputToContain('Limit alert flags would be reset on 1 budget period(s).')
        ->assertSuccessful();

    expect($this->period->fresh()->over_limit_notified)->toBeTrue();
});

test('a period whose rows change but whose total does not keeps its flags', function () {
    // Stored at 0 where it weighs 50 EUR: +50 EUR, cancelling the -50 EUR of the other USD row.
    $underCounted = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->usdExpense->account_id,
        'category_id' => $this->usdExpense->category_id,
        'currency_code' => 'USD',
        'amount' => -10000,
        'transaction_date' => '2026-10-10',
    ]);
    BudgetTransaction::query()->updateOrCreate(
        ['budget_period_id' => $this->period->id, 'transaction_id' => $underCounted->id],
        ['amount' => 0],
    );
    // Set last: assigning the new transaction live re-evaluates the flags.
    $this->period->update(['over_limit_notified' => true]);

    $this->artisan('budgets:resnapshot-foreign-currency', ['--force' => true])
        ->expectsOutputToContain('2 budget row(s) across 1 user(s) and 1 budget period(s) were rewritten. Spending delta: 0.00 EUR.')
        ->expectsOutputToContain('Limit alert flags were reset on 0 budget period(s).')
        ->assertSuccessful();

    expect(storedBudgetAmount($underCounted, $this->period))->toBe(5000)
        ->and($this->period->fresh()->over_limit_notified)->toBeTrue();
});

test('the reported delta uses the user currency decimals', function () {
    // 10 USD buy 40000 COP, a currency with no decimals.
    ExchangeRate::factory()->create(['base_currency' => 'cop', 'date' => '2026-10-10', 'rates' => ['usd' => 0.00025]]);
    $copUser = User::factory()->create(['currency_code' => 'COP']);
    [$copPeriod, $usdExpense, $eurExpense] = staleForeignCurrencyBudget($copUser);
    BudgetTransaction::query()->where('transaction_id', $eurExpense->id)->delete();
    $usdExpense->update(['amount' => -1000]);
    BudgetTransaction::query()->where('transaction_id', $usdExpense->id)->update(['amount' => 1000]);

    $this->artisan('budgets:resnapshot-foreign-currency', ['--force' => true, '--user' => $copUser->id])
        ->expectsOutputToContain('Spending delta: +39000 COP.')
        ->assertSuccessful();

    expect(storedBudgetAmount($usdExpense, $copPeriod))->toBe(40000);
});

test('verbose output lists every row that changes', function () {
    $this->artisan('budgets:resnapshot-foreign-currency', ['-v' => true])
        ->expectsOutputToContain("transaction {$this->usdExpense->id} (2026-10-10): 10000 → 5000 (minor units)")
        ->assertSuccessful();
});
