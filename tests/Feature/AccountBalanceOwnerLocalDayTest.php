<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withHeaders;

/*
 * 22:00 on 24 November in New York is already 25 November in UTC, the
 * server's clock. A balance written "today" must land on the owner's day,
 * the 24th, or the accounts list (which reads up to the end of the owner's
 * day) leaves it out and shows 0.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-24 22:00', 'America/New_York'));

    fakeCurrencyApi();

    $this->user = User::factory()->onboarded()->create([
        'timezone' => 'America/New_York',
        'currency_code' => 'USD',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('dates the starting balance of a new account by the owner\'s day and shows it on the accounts list', function () {
    actingAs($this->user);

    $this->post(route('accounts.store'), [
        'name' => 'Evening Checking',
        'bank_id' => Bank::factory()->create()->id,
        'currency_code' => 'USD',
        'type' => AccountType::Checking->value,
        'balance' => 150000,
    ])->assertRedirect();

    $account = Account::query()->where('user_id', $this->user->id)->sole();

    expect($account->balances()->sole()->balance_date->toDateString())->toBe('2026-11-24');

    $this->get(route('accounts.list'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where("accountMetrics.{$account->id}.currentBalance", 150000)
            )
        );
});

it('defaults a new loan\'s start date to the owner\'s day', function () {
    actingAs($this->user);

    $this->post(route('accounts.store'), [
        'name' => 'Evening Mortgage',
        'bank_id' => Bank::factory()->create()->id,
        'currency_code' => 'USD',
        'type' => AccountType::Loan->value,
        'balance' => 20000000,
        'original_amount' => 20000000,
        'loan_term_months' => 360,
        'annual_interest_rate' => 3.5,
    ])->assertRedirect();

    $account = Account::query()->where('user_id', $this->user->id)->sole();

    expect($account->loanDetail->start_date->toDateString())->toBe('2026-11-24')
        ->and($account->balances()->pluck('balance_date')->map->toDateString()->all())->toBe(['2026-11-24']);
});

it('writes the current balance on the owner\'s day', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    $this->actingAs($this->user)
        ->putJson("/api/accounts/{$account->id}/balance/current", ['balance' => 99000])
        ->assertSuccessful();

    expect(AccountBalance::query()->where('account_id', $account->id)->sole()->balance_date->toDateString())
        ->toBe('2026-11-24');
});

it('dates a balance recorded through the API without a date by the owner\'s day', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);

    withHeaders(apiV1Bearer($this->user, ['api:read', 'api:write']))
        ->postJson("/api/v1/accounts/{$account->id}/balances", ['balance' => 42000])
        ->assertSuccessful();

    expect(AccountBalance::query()->where('account_id', $account->id)->sole()->balance_date->toDateString())
        ->toBe('2026-11-24');
});
