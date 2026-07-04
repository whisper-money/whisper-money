<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\RealEstateDetail;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);

    // The balance-evolution endpoint converts account currency via the external
    // currency-rate provider; fake it so these tests stay hermetic under the
    // stray-request guard instead of hitting the CDN.
    fakeCurrencyApi();

    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('guests are redirected to the registration page for accounts index', function () {
    auth()->logout();

    $this->get(route('accounts.list'))->assertRedirect(route('register'));
});

test('guests are redirected to the registration page for account show', function () {
    auth()->logout();

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    $this->get(route('accounts.show', $account))->assertRedirect(route('register'));
});

test('authenticated users can visit the accounts index', function () {
    $this->get(route('accounts.list'))->assertOk();
});

test('accounts index returns accounts grouped by type', function () {
    $checking = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);
    $savings = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
    ]);
    $investment = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 3)
        );
});

test('accounts are ordered by position then name', function () {
    $third = Account::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Third',
        'position' => 2,
    ]);
    $first = Account::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'First',
        'position' => 0,
    ]);
    $second = Account::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Second',
        'position' => 1,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 3)
            ->where('accounts.0.id', $first->id)
            ->where('accounts.1.id', $second->id)
            ->where('accounts.2.id', $third->id)
        );
});

test('users can reorder their accounts', function () {
    $a = Account::factory()->create(['user_id' => $this->user->id, 'position' => 0]);
    $b = Account::factory()->create(['user_id' => $this->user->id, 'position' => 1]);
    $c = Account::factory()->create(['user_id' => $this->user->id, 'position' => 2]);

    $this->patch(route('accounts.reorder'), ['ids' => [$c->id, $a->id, $b->id]])
        ->assertRedirect();

    expect($c->fresh()->position)->toBe(0);
    expect($a->fresh()->position)->toBe(1);
    expect($b->fresh()->position)->toBe(2);
});

test('users cannot reorder accounts they do not own', function () {
    $other = Account::factory()->create([
        'user_id' => User::factory()->create()->id,
    ]);

    $this->patch(route('accounts.reorder'), ['ids' => [$other->id]])
        ->assertSessionHasErrors('ids.0');

    expect($other->fresh()->position)->toBe(0);
});

test('users can toggle dashboard visibility of their accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'hidden_on_dashboard' => false,
    ]);

    $this->patch(route('accounts.visibility', $account), ['hidden' => true])
        ->assertRedirect();

    expect($account->fresh()->hidden_on_dashboard)->toBeTrue();

    $this->patch(route('accounts.visibility', $account), ['hidden' => false])
        ->assertRedirect();

    expect($account->fresh()->hidden_on_dashboard)->toBeFalse();
});

test('the hidden flag is required when toggling visibility', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);

    $this->patch(route('accounts.visibility', $account), [])
        ->assertSessionHasErrors('hidden');
});

test('users cannot toggle visibility of accounts they do not own', function () {
    $other = Account::factory()->create([
        'user_id' => User::factory()->create()->id,
        'hidden_on_dashboard' => false,
    ]);

    $this->patch(route('accounts.visibility', $other), ['hidden' => true])
        ->assertForbidden();

    expect($other->fresh()->hidden_on_dashboard)->toBeFalse();
});

test('accounts index only shows user accounts', function () {
    $myAccount = Account::factory()->create([
        'user_id' => $this->user->id,
    ]);
    $otherAccount = Account::factory()->create([
        'user_id' => User::factory()->create()->id,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 1)
            ->where('accounts.0.id', $myAccount->id)
        );
});

test('authenticated users can view their own account', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account')
            ->where('account.id', $account->id)
        );
});

test('users cannot view other users accounts', function () {
    $otherUser = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertForbidden();
});

test('account show includes categories, accounts, and banks', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
    ]);

    Category::factory()->count(3)->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account')
            ->has('categories', 3)
            ->has('accounts', 1)
            ->has('banks')
        );
});

test('account show includes the account transactions and excludes other accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    $otherAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    Transaction::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $otherAccount->id,
    ]);

    $this->get(route('accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->missing('transactions')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('transactions', 2)
            )
        );
});

test('account show returns no transactions for non-transactional accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
    ]);

    $this->get(route('accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 0)
        );
});

test('account show includes shared labels and automation rules for transaction tools', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $label = Label::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Groceries',
    ]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'title' => 'Apply groceries label',
    ]);

    $rule->labels()->attach($label);

    $response = $this->withoutVite()->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('labels', 1)
            ->where('labels.0.id', $label->id)
            ->has('automationRules', 1)
            ->where('automationRules.0.id', $rule->id)
        );
});

test('account balance evolution returns data for single account', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 150000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data)->toHaveKeys(['data', 'account']);
    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKeys(['month', 'timestamp', 'value']);
    expect($data['account']['id'])->toBe($account->id);
});

test('account balance evolution denies access to other users accounts', function () {
    $otherUser = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => now()->subMonth()->toDateString(),
        'to' => now()->toDateString(),
    ]));

    $response->assertForbidden();
});

test('accounts index defers account metrics', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->subMonthNoOverflow()->startOfMonth(),
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth(),
        'balance' => 150000,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 1)
            ->missing('accountMetrics')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('accountMetrics')
                ->has("accountMetrics.{$account->id}")
                ->has("accountMetrics.{$account->id}.currentBalance")
                ->has("accountMetrics.{$account->id}.previousBalance")
                ->has("accountMetrics.{$account->id}.diff")
                ->has("accountMetrics.{$account->id}.history")
                ->where("accountMetrics.{$account->id}.currentBalance", 150000)
                ->where("accountMetrics.{$account->id}.previousBalance", 100000)
                ->where("accountMetrics.{$account->id}.diff", 50000)
            )
        );
});

test('accounts index deferred metrics includes invested amount for investment accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->withInvestedAmount(80000)->create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth(),
        'balance' => 120000,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->missing('accountMetrics')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where("accountMetrics.{$account->id}.investedAmount", 80000)
                ->where("accountMetrics.{$account->id}.currentBalance", 120000)
            )
        );
});

test('accounts index deferred metrics returns null invested amount for non-investment accounts', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->startOfMonth(),
        'balance' => 100000,
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('accountMetrics')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where("accountMetrics.{$account->id}.investedAmount", null)
            )
        );
});

test('account show includes bank information', function () {
    $bank = Bank::factory()->create([
        'name' => 'Test Bank',
        'logo' => 'https://example.com/logo.png',
    ]);

    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'bank_id' => $bank->id,
    ]);

    $response = $this->get(route('accounts.show', $account));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account.bank')
            ->where('account.bank.name', 'Test Bank')
        );
});

test('real estate account show includes real estate detail with linked loan', function () {
    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
    ]);

    $realEstateAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $realEstateAccount->id,
        'linked_loan_account_id' => $loanAccount->id,
    ]);

    $response = $this->withoutVite()->get(route('accounts.show', $realEstateAccount));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->has('account.real_estate_detail')
            ->where('account.real_estate_detail.linked_loan_account_id', $loanAccount->id)
            ->has('account.real_estate_detail.linked_loan_account')
            ->where('account.real_estate_detail.linked_loan_account.id', $loanAccount->id)
            ->has('account.available_loan_accounts')
        );
});

test('real estate account show includes current market value and loan balance for equity', function () {
    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $loanAccount->id,
        'balance_date' => now(),
        'balance' => 20000000, // $200,000
    ]);

    $realEstateAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $realEstateAccount->id,
        'balance_date' => now(),
        'balance' => 35000000, // $350,000
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $realEstateAccount->id,
        'linked_loan_account_id' => $loanAccount->id,
    ]);

    $response = $this->withoutVite()->get(route('accounts.show', $realEstateAccount));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->where('account.real_estate_detail.current_market_value', 35000000)
            ->where('account.real_estate_detail.current_loan_balance', 20000000)
        );
});

test('real estate account show includes market value without linked loan', function () {
    $realEstateAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $realEstateAccount->id,
        'balance_date' => now(),
        'balance' => 35000000,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $realEstateAccount->id,
    ]);

    $response = $this->withoutVite()->get(route('accounts.show', $realEstateAccount));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Show')
            ->where('account.real_estate_detail.current_market_value', 35000000)
            ->missing('account.real_estate_detail.current_loan_balance')
        );
});

test('real estate balance evolution includes mortgage balance data', function () {
    $loanAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Loan,
        'currency_code' => 'EUR',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $loanAccount->id,
        'balance_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'balance' => 22000000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $loanAccount->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 21500000,
    ]);

    $realEstateAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $realEstateAccount->id,
        'balance_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'balance' => 35000000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $realEstateAccount->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 36000000,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $realEstateAccount->id,
        'linked_loan_account_id' => $loanAccount->id,
        'revaluation_percentage' => null,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$realEstateAccount->id.'/balance-evolution?'.http_build_query([
        'from' => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'])->toHaveCount(3);
    expect($data['data'][0])->toHaveKey('mortgage_balance');

    // The last point should have the most recent mortgage balance
    $lastPoint = end($data['data']);
    expect($lastPoint['mortgage_balance'])->toBe(21500000);
    expect($lastPoint['value'])->toBe(36000000);
});

test('real estate balance evolution without linked loan has no mortgage data', function () {
    $realEstateAccount = Account::factory()->realEstate()->create([
        'user_id' => $this->user->id,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $realEstateAccount->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 35000000,
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $realEstateAccount->id,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$realEstateAccount->id.'/balance-evolution?'.http_build_query([
        'from' => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0])->not->toHaveKey('mortgage_balance');
});

test('non-real-estate account balance evolution has no mortgage data', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => now()->endOfMonth(),
        'balance' => 100000,
    ]);

    $response = $this->getJson('/api/dashboard/account/'.$account->id.'/balance-evolution?'.http_build_query([
        'from' => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
        'to' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['data'][0])->not->toHaveKey('mortgage_balance');
});

test('accounts index serializes the standard account field set without sensitive columns', function () {
    Account::factory()->create([
        'user_id' => $this->user->id,
        'iban' => 'ES9121000418450200051332',
    ]);

    $response = $this->get(route('accounts.list'));

    $account = $response->viewData('page')['props']['accounts'][0];

    expect(array_keys($account))->toEqualCanonicalizing([
        'id', 'name', 'name_iv', 'encrypted', 'type', 'currency_code',
        'banking_connection_id', 'external_account_id', 'linked_at',
        'bank', 'linked_loan_account_id',
    ]);
    expect($account)->not->toHaveKeys(['user_id', 'bank_id', 'iban', 'created_at', 'updated_at', 'deleted_at']);
});
