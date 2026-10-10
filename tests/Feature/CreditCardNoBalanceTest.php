<?php

use App\Enums\CategoryType;
use App\Features\CreditCardStatements;
use App\Models\Account;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;

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

it('opens a credit card without a balance while the flag is on', function () {
    Feature::for($this->user)->activate(CreditCardStatements::class);

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

it('still opens a credit card with its balance while the flag is off', function () {
    actingAs($this->user)
        ->post(route('accounts.store'), [
            'name' => 'Visa',
            'type' => 'credit_card',
            'currency_code' => 'EUR',
            'balance' => -50000,
        ])
        ->assertSessionHasNoErrors();

    expect(Account::query()->where('name', 'Visa')->sole()->balances()->sole()->balance)->toBe(-50000);
});

it('keeps the opening balance of other account types while the flag is on', function () {
    Feature::for($this->user)->activate(CreditCardStatements::class);

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

it('sends the usage of each credit card to the accounts list while the flag is on', function () {
    Feature::for($this->user)->activate(CreditCardStatements::class);
    $card = cardWithPurchase(124000, limit: 300000);
    $cardWithoutLimit = cardWithPurchase(5000, limit: null);
    $checking = Account::factory()->create(['user_id' => $this->user->id]);

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

it('sends no credit card usage to the accounts list while the flag is off', function () {
    cardWithPurchase(124000, limit: 300000);

    actingAs($this->user)
        ->get(route('accounts.list'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('accountMetrics')
                ->missing('creditCardUsage')
            )
        );
});

it('works out the usage of every card in one query each', function () {
    Feature::for($this->user)->activate(CreditCardStatements::class);
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

it('sends the usage of each live credit card to the dashboard while the flag is on', function () {
    Feature::for($this->user)->activate(CreditCardStatements::class);
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

it('sends no credit card usage to the dashboard while the flag is off', function () {
    cardWithPurchase(124000, limit: 300000);

    actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('dashboard', fn ($reload) => $reload
                ->has('netWorthEvolution')
                ->missing('creditCardUsage')
            )
        );
});
