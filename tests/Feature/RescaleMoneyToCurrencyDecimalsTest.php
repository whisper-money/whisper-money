<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\LoanDetail;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The migration runs against an empty database during RefreshDatabase, so these
 * seed rows at the old fixed scale of 2 and run `up()` again over them.
 */
function rescaleMoney(string $direction = 'up'): void
{
    $migration = require database_path('migrations/2026_08_28_122633_rescale_money_to_currency_decimals.php');

    $migration->{$direction}();
}

it('drops the minor unit of a zero-decimal currency, rounding half away from zero', function () {
    $account = Account::factory()->create(['currency_code' => 'COP']);
    $exact = Transaction::factory()->create(['account_id' => $account->id, 'currency_code' => 'COP', 'amount' => 123400]);
    $roundsUp = Transaction::factory()->create(['account_id' => $account->id, 'currency_code' => 'COP', 'amount' => 123456]);
    $roundsDown = Transaction::factory()->create(['account_id' => $account->id, 'currency_code' => 'COP', 'amount' => 123421]);
    $negative = Transaction::factory()->create(['account_id' => $account->id, 'currency_code' => 'COP', 'amount' => -123456]);

    rescaleMoney();

    expect($exact->refresh()->amount)->toBe(1234)
        ->and($roundsUp->refresh()->amount)->toBe(1235)
        ->and($roundsDown->refresh()->amount)->toBe(1234)
        ->and($negative->refresh()->amount)->toBe(-1235);
});

it('grows BTC to satoshis and KWD to three decimals', function () {
    $btc = Account::factory()->create(['currency_code' => 'BTC']);
    $kwd = Account::factory()->create(['currency_code' => 'KWD']);
    $btcBalance = AccountBalance::factory()->create(['account_id' => $btc->id, 'balance' => 80]);
    $kwdBalance = AccountBalance::factory()->create(['account_id' => $kwd->id, 'balance' => 35135]);

    rescaleMoney();

    // 0.80 BTC keeps its value and gains six zeros; the precision truncated
    // before this migration is not recoverable.
    expect($btcBalance->refresh()->balance)->toBe(80_000_000)
        ->and($kwdBalance->refresh()->balance)->toBe(351_350);
});

it('leaves two-decimal currencies untouched', function () {
    $account = Account::factory()->create(['currency_code' => 'EUR']);
    $transaction = Transaction::factory()->create(['account_id' => $account->id, 'currency_code' => 'EUR', 'amount' => 123456]);
    $balance = AccountBalance::factory()->create(['account_id' => $account->id, 'balance' => 987654]);

    rescaleMoney();

    expect($transaction->refresh()->amount)->toBe(123456)
        ->and($balance->refresh()->balance)->toBe(987654);
});

it('rescales the money columns that have no currency of their own', function () {
    $user = User::factory()->create(['currency_code' => 'COP']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'COP']);

    $balance = AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance' => 500000,
        'invested_amount' => 250000,
    ]);
    $loan = LoanDetail::factory()->create(['account_id' => $account->id, 'original_amount' => 900000]);
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 700000,
        'initial_amount' => 100000,
    ]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'allocated_amount' => 320000,
        'carried_over_amount' => 4500,
    ]);

    rescaleMoney();

    expect($balance->refresh()->balance)->toBe(5000)
        ->and($balance->invested_amount)->toBe(2500)
        ->and($loan->refresh()->original_amount)->toBe(9000)
        ->and($goal->refresh()->target_amount)->toBe(7000)
        ->and($goal->initial_amount)->toBe(1000)
        ->and($period->refresh()->allocated_amount)->toBe(3200)
        ->and($period->carried_over_amount)->toBe(45);
});

it('follows the transaction currency for a budget transaction, not the owner primary', function () {
    // A COP user spending from a EUR account: the snapshot is a copy of the
    // EUR transaction, so it must stay at two decimals.
    $user = User::factory()->create(['currency_code' => 'COP']);
    $eurAccount = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $eurTransaction = Transaction::factory()->create(['account_id' => $eurAccount->id, 'currency_code' => 'EUR', 'amount' => -5000]);

    $copAccount = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'COP']);
    $copTransaction = Transaction::factory()->create(['account_id' => $copAccount->id, 'currency_code' => 'COP', 'amount' => -600000]);

    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $period = BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'allocated_amount' => 0]);
    $fromEur = BudgetTransaction::factory()->create(['transaction_id' => $eurTransaction->id, 'budget_period_id' => $period->id, 'amount' => 5000]);
    $fromCop = BudgetTransaction::factory()->create(['transaction_id' => $copTransaction->id, 'budget_period_id' => $period->id, 'amount' => 600000]);

    rescaleMoney();

    expect($fromEur->refresh()->amount)->toBe(5000)
        ->and($fromCop->refresh()->amount)->toBe(6000);
});

it('preserves nulls', function () {
    $account = Account::factory()->create(['currency_code' => 'COP']);
    $balance = AccountBalance::factory()->create(['account_id' => $account->id, 'balance' => 100, 'invested_amount' => null]);

    rescaleMoney();

    expect($balance->refresh()->invested_amount)->toBeNull();
});

it('does not touch updated_at, so the offline sync cursor stays put', function () {
    $account = Account::factory()->create(['currency_code' => 'COP']);
    $transaction = Transaction::factory()->create(['account_id' => $account->id, 'currency_code' => 'COP', 'amount' => 123456]);
    $before = DB::table('transactions')->where('id', $transaction->id)->value('updated_at');

    rescaleMoney();

    expect(DB::table('transactions')->where('id', $transaction->id)->value('updated_at'))->toBe($before);
});

it('restores the scale on the way down', function () {
    $btc = Account::factory()->create(['currency_code' => 'BTC']);
    $balance = AccountBalance::factory()->create(['account_id' => $btc->id, 'balance' => 80]);

    rescaleMoney();
    rescaleMoney('down');

    expect($balance->refresh()->balance)->toBe(80);
});

it('widens archived_saved_amount so a high-decimal currency cannot overflow it', function () {
    rescaleMoney();

    $user = User::factory()->create(['currency_code' => 'KWD']);
    $goal = SavingsGoal::factory()->create([
        'user_id' => $user->id,
        'target_amount' => 1000,
        'archived_saved_amount' => 5_000_000_000,
    ]);

    expect($goal->refresh()->archived_saved_amount)->toBe(5_000_000_000);
});
