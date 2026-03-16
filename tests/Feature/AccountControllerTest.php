<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('guests are redirected to the login page for accounts index', function () {
    auth()->logout();

    $this->get(route('accounts.list'))->assertRedirect(route('login'));
});

test('guests are redirected to the login page for account show', function () {
    auth()->logout();

    $account = Account::factory()->create(['user_id' => $this->user->id]);

    $this->get(route('accounts.show', $account))->assertRedirect(route('login'));
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

test('accounts are ordered by type then name', function () {
    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'name' => 'A Savings',
    ]);
    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'B Checking',
    ]);
    Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'name' => 'A Checking',
    ]);

    $response = $this->get(route('accounts.list'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 3)
            ->where('accounts.0.type', 'checking')
            ->where('accounts.0.name', 'A Checking')
            ->where('accounts.1.type', 'checking')
            ->where('accounts.1.name', 'B Checking')
            ->where('accounts.2.type', 'savings')
        );
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
