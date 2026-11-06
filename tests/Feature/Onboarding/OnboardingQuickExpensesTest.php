<?php

use App\Actions\CreateDefaultCategories;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Somebody mid-onboarding with the default categories every signup gets. */
function quickExpensesUser(array $attributes = []): User
{
    $user = User::factory()->create([
        'onboarded_at' => null,
        'currency_code' => 'EUR',
        ...$attributes,
    ]);

    (new CreateDefaultCategories)->handle($user);

    return $user;
}

function manualAccount(User $user): Account
{
    return Account::factory()->for($user)->create([
        'type' => 'checking',
        'currency_code' => 'EUR',
        'banking_connection_id' => null,
    ]);
}

afterEach(function () {
    Carbon::setTestNow();
});

it('stores each expense as a categorized estimate dated last month', function () {
    Carbon::setTestNow('2026-11-06 12:00:00');
    $user = quickExpensesUser();
    $account = manualAccount($user);

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $account->id,
            'expenses' => [
                ['kind' => 'housing', 'amount' => 90000],
                ['kind' => 'groceries', 'amount' => 35000],
            ],
        ])
        ->assertCreated()
        ->assertJson(['count' => 2]);

    $transactions = Transaction::query()->where('account_id', $account->id)->with(['labels', 'category'])->get();

    expect($transactions)->toHaveCount(2);

    $rent = $transactions->firstWhere('description', 'Rent or mortgage');
    expect($rent->amount)->toBe(-90000)
        ->and($rent->currency_code)->toBe('EUR')
        ->and($rent->transaction_date->toDateString())->toBe('2026-10-01')
        ->and($rent->source)->toBe(TransactionSource::ManuallyCreated)
        ->and($rent->category->name)->toBe('Rent and maintanence')
        ->and($rent->labels->pluck('name')->all())->toBe(['Estimate']);

    expect($transactions->firstWhere('description', 'Groceries')->category->name)->toBe('Groceries');
    expect(Label::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('dates the estimates in the month before the user’s own today', function () {
    // Still October in New York while it is already November in UTC.
    Carbon::setTestNow('2026-11-01 02:00:00');
    $user = quickExpensesUser(['timezone' => 'America/New_York']);
    $account = manualAccount($user);

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $account->id,
            'expenses' => [['kind' => 'transport', 'amount' => 5000]],
        ])
        ->assertCreated();

    expect(Transaction::query()->where('account_id', $account->id)->sole()->transaction_date->toDateString())
        ->toBe('2026-09-01');
});

it('reuses the Estimate label and writes in the user’s language', function () {
    $user = quickExpensesUser(['locale' => 'es']);
    $account = manualAccount($user);
    $label = Label::factory()->for($user)->create(['name' => 'Estimación']);

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $account->id,
            'expenses' => [['kind' => 'groceries', 'amount' => 30000]],
        ])
        ->assertCreated();

    $transaction = Transaction::query()->where('account_id', $account->id)->with(['labels', 'category'])->sole();

    expect($transaction->description)->toBe('Supermercado')
        ->and($transaction->category->name)->toBe('Supermercado')
        ->and($transaction->labels->pluck('id')->all())->toBe([$label->id])
        ->and(Label::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('leaves an expense uncategorized when its category is gone', function () {
    $user = quickExpensesUser();
    $account = manualAccount($user);
    Category::query()->where('user_id', $user->id)->where('name', 'Insurance')->delete();

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $account->id,
            'expenses' => [['kind' => 'insurance', 'amount' => 4000]],
        ])
        ->assertCreated();

    expect(Transaction::query()->where('account_id', $account->id)->sole()->category_id)->toBeNull();
});

it('does not move the account balance', function () {
    $user = quickExpensesUser();
    $account = manualAccount($user);

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $account->id,
            'expenses' => [['kind' => 'housing', 'amount' => 90000]],
        ])
        ->assertCreated();

    expect($account->balances()->count())->toBe(0);
});

it('rejects an empty, zero or repeated expense list', function (array $expenses) {
    $user = quickExpensesUser();
    $account = manualAccount($user);

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $account->id,
            'expenses' => $expenses,
        ])
        ->assertUnprocessable();

    expect(Transaction::query()->count())->toBe(0);
})->with([
    'none' => [[]],
    'zero' => [[['kind' => 'housing', 'amount' => 0]]],
    'negative' => [[['kind' => 'housing', 'amount' => -100]]],
    'unknown kind' => [[['kind' => 'pets', 'amount' => 100]]],
    'repeated' => [[['kind' => 'housing', 'amount' => 100], ['kind' => 'housing', 'amount' => 200]]],
]);

it('refuses an account that is not the user’s', function () {
    $user = quickExpensesUser();
    $someoneElse = manualAccount(User::factory()->create());

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $someoneElse->id,
            'expenses' => [['kind' => 'housing', 'amount' => 100]],
        ])
        ->assertNotFound();
});

it('refuses a connected account and one with no ledger', function (array $attributes) {
    $user = quickExpensesUser();
    $account = Account::factory()->for($user)->create([
        'currency_code' => 'EUR',
        'type' => 'checking',
        ...$attributes,
    ]);

    if (($attributes['type'] ?? null) === null) {
        $account->update(['banking_connection_id' => BankingConnection::factory()->for($user)->create()->id]);
    }

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $account->id,
            'expenses' => [['kind' => 'housing', 'amount' => 100]],
        ])
        ->assertUnprocessable();

    expect(Transaction::query()->count())->toBe(0);
})->with([
    'connected' => [[]],
    'balance only' => [['type' => 'investment']],
]);

it('turns a couple of estimates into a spending reveal', function () {
    $user = quickExpensesUser();
    $account = manualAccount($user);

    $this->actingAs($user)
        ->postJson('/onboarding/quick-expenses', [
            'account_id' => $account->id,
            'expenses' => [
                ['kind' => 'housing', 'amount' => 90000],
                ['kind' => 'groceries', 'amount' => 35000],
            ],
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson([
            'variant' => 'spending',
            'month' => now()->startOfMonth()->subMonth()->format('Y-m'),
            'is_last_month' => true,
            'spent' => 125000,
        ]);
});

it('still needs five movements for a month with no estimates in it', function () {
    $user = quickExpensesUser();
    $account = manualAccount($user);
    Transaction::factory()->for($user)->for($account)->create([
        'transaction_date' => now()->startOfMonth()->subMonth()->addDays(3),
        'amount' => -1000,
        'currency_code' => 'EUR',
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson(['variant' => 'assets']);
});
