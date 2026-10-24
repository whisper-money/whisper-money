<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BalanceLookup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

/**
 * The bindings of every query against `transactions` the lookup runs while it
 * is built.
 *
 * @return list<list<mixed>>
 */
function transactionQueriesWhile(Closure $build): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $build();

    $queries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => preg_match('/[`"]transactions[`"]/', $query['query']) === 1)
        ->map(fn (array $query): array => $query['bindings'])
        ->values()
        ->all();

    DB::disableQueryLog();

    return $queries;
}

/**
 * A balance on file before the range is the answer for every day inside it,
 * so the account's movements are never needed and never read.
 */
test('getBalanceAt does not read movements for an account seeded before the range', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-20',
        'balance' => 100000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-03-15',
        'balance' => 160000,
    ]);

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-10',
        'amount' => -30000,
    ]);
    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-02-10',
        'amount' => 60000,
    ]);

    $lookup = null;
    $queries = transactionQueriesWhile(function () use (&$lookup): void {
        $lookup = BalanceLookup::forAccounts(
            [$this->account->id],
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-03-31'),
        );
    });

    expect($queries)->toBe([])
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-01')))->toBe(100000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-28')))->toBe(100000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-15')))->toBe(160000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-31')))->toBe(160000);
});

/**
 * Walking a balance back to a day inside the range only ever crosses movements
 * inside the range: older ones and ones after the range end change nothing.
 */
test('getBalanceAt walks back through movements inside the range only', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-03-31',
        'balance' => 100000,
    ]);

    foreach ([['2025-11-20', -70000], ['2026-02-01', -10000], ['2026-03-10', 50000], ['2026-04-05', 99000]] as [$date, $amount]) {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->account->id,
            'transaction_date' => $date,
            'amount' => $amount,
        ]);
    }

    $lookup = null;
    $queries = transactionQueriesWhile(function () use (&$lookup): void {
        $lookup = BalanceLookup::forAccounts(
            [$this->account->id],
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-03-31'),
        );
    });

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toBe([$this->account->id, '2026-02-01', '2026-03-31'])
        // The range start's own movement is already inside its closing balance.
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-01')))->toBe(50000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-28')))->toBe(50000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-10')))->toBe(100000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-31')))->toBe(100000);
});

/**
 * An account that moved before the range but not inside it still has a
 * history, so the later balance stands for the quiet days in between rather
 * than being dropped to nothing.
 */
test('getBalanceAt repeats a later balance when the account only moved before the range', function () {
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

    $lookup = null;
    $queries = transactionQueriesWhile(function () use (&$lookup): void {
        $lookup = BalanceLookup::forAccounts(
            [$this->account->id],
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-03-31'),
        );
    });

    // The grouped movements inside the range, then whether it moved before it.
    expect($queries)->toHaveCount(2)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-28')))->toBe(100000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-31')))->toBe(100000);
});

/**
 * A deleted movement is not a movement: it no more counts as history before
 * the range than it counts towards a walk back.
 */
test('getBalanceAt ignores deleted movements before the range', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-03-15',
        'balance' => 100000,
    ]);

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-01-10',
        'amount' => -20000,
    ])->delete();

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-03-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-28')))->toBe(0);
});

/**
 * Movements before the range are never read, so a walk back to a day before it
 * would be answered from half of them. It is refused instead of guessed at.
 */
test('getBalanceAt refuses to walk a balance back past the range start', function () {
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
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-03-31'),
    );

    expect(fn () => $lookup->getBalanceAt($this->account->id, Carbon::parse('2026-01-31')))
        ->toThrow(LogicException::class);
});

/**
 * Movements after the range end are not part of any walk back, so an account
 * whose only movements are later still has nothing to be walked back through.
 */
test('getBalanceAt stays silent when the only movements are after the range', function () {
    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-03-15',
        'balance' => 100000,
    ]);

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-04-10',
        'amount' => -20000,
    ]);

    $lookup = BalanceLookup::forAccounts(
        [$this->account->id],
        Carbon::parse('2026-02-01'),
        Carbon::parse('2026-03-31'),
    );

    expect($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-28')))->toBe(0)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-31')))->toBe(100000);
});

/**
 * A seeded account and one connected inside the range, read together: each is
 * answered from its own history and the seeded one's movements stay unread.
 */
test('getBalanceAt mixes seeded and walked-back accounts in one lookup', function () {
    $connected = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Savings,
        'currency_code' => 'USD',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $this->account->id,
        'balance_date' => '2026-01-31',
        'balance' => 300000,
    ]);
    AccountBalance::factory()->create([
        'account_id' => $connected->id,
        'balance_date' => '2026-03-31',
        'balance' => 80000,
    ]);

    Transaction::factory()->for($this->user)->create([
        'account_id' => $this->account->id,
        'transaction_date' => '2026-02-15',
        'amount' => -50000,
    ]);
    Transaction::factory()->for($this->user)->create([
        'account_id' => $connected->id,
        'transaction_date' => '2026-03-01',
        'amount' => 30000,
    ]);

    $lookup = null;
    $queries = transactionQueriesWhile(function () use (&$lookup, $connected): void {
        $lookup = BalanceLookup::forAccounts(
            [$this->account->id, $connected->id],
            Carbon::parse('2026-02-01'),
            Carbon::parse('2026-03-31'),
        );
    });

    // Only the connected account's movements, and no EXISTS: it moved in range.
    expect($queries)->toBe([[$connected->id, '2026-02-01', '2026-03-31']])
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-02-28')))->toBe(300000)
        ->and($lookup->getBalanceAt($this->account->id, Carbon::parse('2026-03-31')))->toBe(300000)
        ->and($lookup->getBalanceAt($connected->id, Carbon::parse('2026-02-28')))->toBe(50000)
        ->and($lookup->getBalanceAt($connected->id, Carbon::parse('2026-03-31')))->toBe(80000);
});
