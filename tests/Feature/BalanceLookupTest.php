<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;
use App\Services\BalanceLookup;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
        'currency_code' => 'USD',
    ]);
});

test('getBalanceAt returns carry-forward balance from before range', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2025-12-15',
        'balance' => 100000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-31')))->toBe(100000);
});

test('getBalanceAt returns zero when no balance exists', function () {
    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(0);
});

test('getBalanceAt returns latest balance on or before date within range', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-05',
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-20',
        'balance' => 200000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-03')))->toBe(0);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-05')))->toBe(100000);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-20')))->toBe(200000);
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-31')))->toBe(200000);
});

test('getBalanceAt works with multiple accounts', function () {
    $account2 = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-10',
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account2->id,
        'balance_date' => '2026-01-10',
        'balance' => 500000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id, $account2->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
    expect($lookup->getBalanceAt($account2->id, Carbon::parse('2026-01-15')))->toBe(500000);
});

test('getInvestedAmountAt returns null when no invested data exists', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-10',
        'balance' => 100000,
        'invested_amount' => null,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getInvestedAmountAt($this->account->id, Carbon::parse('2026-01-15')))->toBeNull();
});

test('getInvestedAmountAt carries forward last known invested amount across null gaps', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-01-05',
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-01-20',
        'balance' => 550000,
        'invested_amount' => null,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getInvestedAmountAt($account->id, Carbon::parse('2026-01-05')))->toBe(400000);
    // After the null entry on Jan 20, invested_amount should still carry forward from Jan 5
    expect($lookup->getInvestedAmountAt($account->id, Carbon::parse('2026-01-25')))->toBe(400000);
});

test('getInvestedAmountAt carries forward from before range', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2025-12-15',
        'balance' => 500000,
        'invested_amount' => 400000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getInvestedAmountAt($account->id, Carbon::parse('2026-01-15')))->toBe(400000);
});

test('getInvestedAmountAt carry-forward seed uses latest non-null invested amount before range', function () {
    $account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'USD',
    ]);

    // Invested amount set early
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2025-12-01',
        'balance' => 400000,
        'invested_amount' => 300000,
    ]);
    // Latest balance before range has null invested_amount
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2025-12-20',
        'balance' => 450000,
        'invested_amount' => null,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    // Should carry forward balance from Dec 20 (latest before range)
    expect($lookup->getBalanceAt($account->id, Carbon::parse('2026-01-10')))->toBe(450000);
    // Should carry forward invested_amount from Dec 1 (latest non-null before range)
    expect($lookup->getInvestedAmountAt($account->id, Carbon::parse('2026-01-10')))->toBe(300000);
});

test('forAccounts handles empty account list', function () {
    $lookup = BalanceLookup::forAccounts(
        [],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt('nonexistent', Carbon::parse('2026-01-15')))->toBe(0);
    expect($lookup->getInvestedAmountAt('nonexistent', Carbon::parse('2026-01-15')))->toBeNull();
});

test('forAccounts accepts a Collection of account IDs', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-10',
        'balance' => 100000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        collect([$this->account->id]),
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
});
