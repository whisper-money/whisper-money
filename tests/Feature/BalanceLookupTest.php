<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
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

/**
 * The shape of a bank connected today: one balance, dated today, and a year of
 * movements behind it. Carried sideways it drew a flat year; walked back
 * through the movements it draws the year that actually happened.
 */
test('getBalanceAt walks a later balance back through the movements since', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-03-31',
        'balance' => 100000,
    ]);

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-02-10',
        'amount' => -30000,
    ]);
    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-03-10',
        'amount' => 50000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-03-31'),
    );

    // Before either movement, and after the outgoing one only.
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-31')))->toBe(80000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-28')))->toBe(50000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-31')))->toBe(100000);
});

/**
 * A balance is the bank's last word on the account, so movements after it are
 * already settled inside it. Adding them on top is what turned a sandbox that
 * dates its balance to 2019 into a six-figure overdraft.
 */
test('getBalanceAt never walks a balance forward past its own date', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2025-12-31',
        'balance' => 100000,
    ]);

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-10',
        'amount' => -25000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-05')))->toBe(100000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-31')))->toBe(100000);
});

/**
 * Two balances with movements between them: the earlier one is already the
 * bank's word on that stretch, so it is carried forward rather than re-derived
 * from the later one.
 */
test('getBalanceAt prefers a balance already recorded before the day asked about', function () {
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

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-10',
        'amount' => 100000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(100000);
});

/**
 * The movements of the day a balance was recorded are already inside it, so
 * counting them again would move the balance away from the one figure we know
 * for certain.
 */
test('getBalanceAt leaves the recorded day itself alone', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-31',
        'balance' => 100000,
    ]);

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-31',
        'amount' => -40000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-31')))->toBe(100000);
});

/**
 * With no movements to walk back through there is nothing to say about a date
 * before the only balance on file, and saying it anyway is the flat line this
 * whole thing exists to stop drawing.
 */
test('getBalanceAt stays silent before a balance it cannot walk back to', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-31',
        'balance' => 100000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-15')))->toBe(0);
});

/**
 * A month where nothing moved still has a balance: the later one, unchanged.
 * Reporting nothing there left a hole in the middle of the chart between two
 * months that both had one.
 */
test('getBalanceAt reports a quiet stretch inside the history', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-03-15',
        'balance' => 100000,
    ]);

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-10',
        'amount' => -20000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2025-12-01'),
        Carbon::parse('2026-03-31'),
    );

    // Nothing moved after January, so February closed on the same balance.
    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-28')))->toBe(100000)
        // Before the movement: the balance it opened on.
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-05')))->toBe(120000);
});
