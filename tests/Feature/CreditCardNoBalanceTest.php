<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Http::fake();
    $this->travelTo('2026-04-15 12:00:00');

    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
});

/**
 * A credit card of the test user with a limit and one purchase this month.
 */
function cardWithPurchase(int $purchase, ?int $limit, array $attributes = []): Account
{
    $card = Account::factory()->creditCard()->create([
        'user_id' => test()->user->id,
        'currency_code' => 'EUR',
        ...$attributes,
    ]);

    if ($limit !== null) {
        CreditCardDetail::factory()->create([
            'account_id' => $card->id,
            'statement_closing_date' => null,
            'payment_due_date' => null,
            'credit_limit' => $limit,
        ]);
    }

    Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => $card->id,
        'category_id' => Category::factory()->create(['user_id' => test()->user->id, 'type' => CategoryType::Expense])->id,
        'currency_code' => 'EUR',
        'amount' => -$purchase,
        'transaction_date' => '2026-04-10',
    ]);

    return $card;
}

// -------------------------------------------------------------------
// Creating a card
// -------------------------------------------------------------------

it('opens a credit card without a balance', function () {
    actingAs($this->user)
        ->post(route('accounts.store'), [
            'name' => 'Visa',
            'type' => 'credit_card',
            'currency_code' => 'EUR',
            'balance' => -50000,
            'credit_limit' => 300000,
        ])
        ->assertSessionHasNoErrors();

    $card = Account::query()->where('name', 'Visa')->sole();

    expect($card->balances()->exists())->toBeFalse()
        ->and($card->creditCardDetail->credit_limit)->toBe(300000);
});

it('keeps the opening balance of other account types', function () {
    actingAs($this->user)
        ->post(route('accounts.store'), [
            'name' => 'Current',
            'type' => 'checking',
            'currency_code' => 'EUR',
            'balance' => 120000,
        ])
        ->assertSessionHasNoErrors();

    expect(Account::query()->where('name', 'Current')->sole()->balances()->sole()->balance)->toBe(120000);
});

// -------------------------------------------------------------------
// Accounts list
// -------------------------------------------------------------------

it('sends the usage of each credit card to the accounts list', function () {
    $card = cardWithPurchase(124000, limit: 300000);
    $cardWithoutLimit = cardWithPurchase(5000, limit: null);
    $checking = Account::factory()->create(['user_id' => $this->user->id, 'type' => AccountType::Checking]);

    actingAs($this->user)
        ->get(route('accounts.list'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->missing('creditCardUsage')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('creditCardUsage', 2)
                ->where("creditCardUsage.{$card->id}.used", 124000)
                ->where("creditCardUsage.{$card->id}.limit", 300000)
                ->where("creditCardUsage.{$card->id}.available", 176000)
                ->where("creditCardUsage.{$cardWithoutLimit->id}.used", 5000)
                ->where("creditCardUsage.{$cardWithoutLimit->id}.limit", null)
                ->missing("creditCardUsage.{$checking->id}")
            )
        );
});

it('treats a bank-connected credit card like a manual one', function () {
    $connectedCard = cardWithPurchase(8000, limit: 200000, attributes: [
        'banking_connection_id' => BankingConnection::factory()->create(['user_id' => $this->user->id])->id,
        'external_account_id' => 'external-card',
    ]);

    actingAs($this->user)
        ->get(route('accounts.list'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where("creditCardUsage.{$connectedCard->id}.used", 8000)
                ->where("creditCardUsage.{$connectedCard->id}.available", 192000)
            )
        );
});

it('works out the usage of every card in one query each', function () {
    cardWithPurchase(1000, limit: 300000);

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        actingAs($this->user)
            ->get(route('accounts.list'), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'Accounts/Index',
                'X-Inertia-Partial-Data' => 'creditCardUsage',
            ])
            ->assertOk();

        return count(DB::getQueryLog());
    };

    // The first visit of the day records the streak; measure after it.
    $countQueries();
    $withOneCard = $countQueries();
    cardWithPurchase(2000, limit: 300000);
    cardWithPurchase(3000, limit: null);

    expect($countQueries())->toBe($withOneCard + 2);
});

// -------------------------------------------------------------------
// Dashboard
// -------------------------------------------------------------------

it('sends the usage of each live credit card to the dashboard', function () {
    $card = cardWithPurchase(124000, limit: 300000);
    $archived = cardWithPurchase(1000, limit: null, attributes: ['archived_at' => now()]);

    actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('creditCardUsage')
            ->loadDeferredProps('dashboard', fn ($reload) => $reload
                ->has('creditCardUsage', 1)
                ->where("creditCardUsage.{$card->id}.used", 124000)
                ->where("creditCardUsage.{$card->id}.limit", 300000)
                ->missing("creditCardUsage.{$archived->id}")
            )
        );
});

// -------------------------------------------------------------------
// Transactions never move a card's balance
// -------------------------------------------------------------------

/**
 * A manual account of the test user holding one balance snapshot.
 */
function accountWithBalance(AccountType $type, int $balance = 10_000): Account
{
    $account = Account::factory()->create([
        'user_id' => test()->user->id,
        'type' => $type,
        'currency_code' => 'EUR',
    ]);
    $account->balances()->create(['balance_date' => '2026-04-01', 'balance' => $balance]);

    return $account;
}

/**
 * @return array<string, int>
 */
function balancesOf(Account $account): array
{
    return $account->balances()->orderBy('balance_date')->pluck('balance', 'balance_date')
        ->mapWithKeys(fn (int $balance, $date): array => [substr((string) $date, 0, 10) => $balance])
        ->all();
}

function storeTransactionUpdatingBalance(Account $account, int $amount): Transaction
{
    $id = actingAs(test()->user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'Groceries',
        'transaction_date' => '2026-04-10',
        'amount' => $amount,
        'currency_code' => 'EUR',
        'source' => 'manually_created',
        'update_balance' => true,
    ])->assertCreated()->json('data.id');

    return Transaction::query()->findOrFail($id);
}

it('leaves a credit card balance alone when a transaction is created, edited or deleted', function () {
    $card = accountWithBalance(AccountType::CreditCard);

    $transaction = storeTransactionUpdatingBalance($card, -2_500);

    actingAs($this->user)->patchJson(route('transactions.update', $transaction), [
        'amount' => -4_000,
        'update_balance' => true,
    ])->assertOk();

    actingAs($this->user)->deleteJson(route('transactions.destroy', $transaction), ['update_balance' => true])
        ->assertOk();

    expect(balancesOf($card))->toBe(['2026-04-01' => 10_000]);
});

it('still moves the balance of other manual accounts', function () {
    $checking = accountWithBalance(AccountType::Checking);

    storeTransactionUpdatingBalance($checking, -2_500);

    expect(balancesOf($checking))->toBe(['2026-04-01' => 10_000, '2026-04-10' => 7_500]);
});

it('unwinds only the manual side when a transaction moves onto a credit card', function () {
    $checking = accountWithBalance(AccountType::Checking);
    $card = accountWithBalance(AccountType::CreditCard);

    $transaction = storeTransactionUpdatingBalance($checking, -2_500);

    actingAs($this->user)->patchJson(route('transactions.update', $transaction), [
        'account_id' => $card->id,
        'update_balance' => true,
    ])->assertOk();

    expect(balancesOf($checking))->toBe(['2026-04-01' => 10_000, '2026-04-10' => 10_000]);
    expect(balancesOf($card))->toBe(['2026-04-01' => 10_000]);
});
