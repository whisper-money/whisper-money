<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;

use function Pest\Laravel\actingAs;

test('calculateHistoricalBalances derives balances from transactions', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    // Reference balance: end of Feb 10, balance = 100000 (€1,000.00)
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    // Transactions: Feb 10 had -5000 (debit), Feb 8 had +20000 (credit), Feb 5 had -10000 (debit)
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-10',
        'amount' => -5000,
    ]);
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => 20000,
    ]);
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-05',
        'amount' => -10000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    // End of Feb 10: 100000 (reference)
    // End of Feb 8: 100000 - (-5000) = 105000 (before Feb 10 transactions)
    // End of Feb 5: 105000 - 20000 = 85000 (before Feb 8 transactions)
    expect($account->balances()->count())->toBe(3);

    $feb8 = $account->balances()->where('balance_date', '2026-02-08')->first();
    expect($feb8->balance)->toBe(105000);

    $feb5 = $account->balances()->where('balance_date', '2026-02-05')->first();
    expect($feb5->balance)->toBe(85000);
});

test('calculateHistoricalBalances counts a moved transaction on the day the bank gave it', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    // Booked by the bank on Feb 8, moved by the user onto Feb 10. The bank's
    // reference balance was reached on the bank's timeline, so Feb 8 is the day
    // this amount has to come off when walking backwards.
    $moved = Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => 20000,
    ]);
    $moved->update(['transaction_date' => '2026-02-10']);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-05',
        'amount' => -10000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    // End of Feb 8: 100000 (nothing the bank dated Feb 10 to strip)
    // End of Feb 5: 100000 - 20000 = 80000
    $feb8 = $account->balances()->where('balance_date', '2026-02-08')->first();
    expect($feb8->balance)->toBe(100000);

    $feb5 = $account->balances()->where('balance_date', '2026-02-05')->first();
    expect($feb5->balance)->toBe(80000);
});

test('calculateHistoricalBalances writes missing balances in one query', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    foreach (range(0, 5) as $daysBack) {
        Transaction::factory()->enableBanking()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'transaction_date' => sprintf('2026-02-%02d', 10 - $daysBack),
            'amount' => -1000,
        ]);
    }

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));

    $result = countQueries(fn () => $service->calculateHistoricalBalances($account));
    $balanceWrites = collect($result['queries'])->filter(fn (string $query): bool => str_contains($query, 'account_balances') && str_contains(strtolower($query), 'insert'));

    expect($balanceWrites)->toHaveCount(1)
        ->and($account->balances()->count())->toBe(6);
});

test('calculateHistoricalBalances handles multiple transactions per day', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    // Two transactions on Feb 8: -3000 and -7000 = total -10000
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => -3000,
    ]);
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => -7000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    // End of Feb 8: 100000 (no transactions between Feb 8 and Feb 10 on the reference date)
    // Wait - there are no transactions on Feb 10, so running_balance stays 100000
    // End of Feb 8: 100000
    expect($account->balances()->count())->toBe(2);

    $feb8 = $account->balances()->where('balance_date', '2026-02-08')->first();
    expect($feb8->balance)->toBe(100000);
});

test('calculateHistoricalBalances leaves a balance of unknown authorship alone', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    // Not flagged as derived, so as far as the walk can tell somebody else put
    // it there - the bank, the user, or a run that predates the flag entirely.
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-05',
        'balance' => 77777,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => 20000,
    ]);
    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-05',
        'amount' => -10000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    // Feb 8 should be calculated, Feb 5 should NOT be overwritten
    expect($account->balances()->count())->toBe(3);

    $feb5 = $account->balances()->where('balance_date', '2026-02-05')->first();
    expect($feb5->balance)->toBe(77777); // Preserved original value
});

test('calculateHistoricalBalances does nothing without reference balance', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-08',
        'amount' => -5000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    expect($account->balances()->count())->toBe(0);
});

test('calculateHistoricalBalances does nothing without transactions', function () {
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-02-10',
        'balance' => 100000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    expect($account->balances()->count())->toBe(1);
});

test('sync marks the balance it stores as reported, not derived', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
        'currency_code' => 'EUR',
    ]);

    // A day the walk had already filled in for itself.
    AccountBalance::factory()->derived()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-03-10',
        'balance' => 100000,
    ]);

    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldReceive('getBalances')->once()->andReturn([
        'balances' => [[
            'balance_type' => 'CLBD',
            'balance_amount' => ['amount' => '1234.56'],
            'reference_date' => '2026-03-10',
        ]],
    ]);

    (new BalanceSyncService($provider))->sync($account);

    $march10 = $account->balances()->where('balance_date', '2026-03-10')->first();

    expect($march10->balance)->toBe(123456)
        ->and($march10->derived)->toBeFalse();
});

/**
 * An account whose bank reported March and January but never February, with the
 * missing month present as imported transactions.
 *
 * Reference balance: end of Mar 10 = 100000.
 * Bank movements:     Mar 10 -5000, Mar 5 +2000, Jan 25 -1000.
 * Imported movements: Feb 20 -30000, Feb 10 +8000 (the hole).
 *
 * @return array{0: User, 1: Account}
 */
function accountWithAGapInItsBankHistory(bool $withImportedGap = true): array
{
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-03-10',
        'balance' => 100000,
    ]);

    $movements = [
        ['2026-03-10', -5000, 'enableBanking'],
        ['2026-03-05', 2000, 'enableBanking'],
        ['2026-02-20', -30000, 'imported'],
        ['2026-02-10', 8000, 'imported'],
        ['2026-01-25', -1000, 'enableBanking'],
    ];

    foreach ($movements as [$date, $amount, $source]) {
        if ($source === 'imported' && ! $withImportedGap) {
            continue;
        }

        Transaction::factory()->{$source}()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'transaction_date' => $date,
            'amount' => $amount,
        ]);
    }

    return [$user, $account];
}

test('calculateHistoricalBalances subtracts imported movements the bank left out of its history', function () {
    [, $account] = accountWithAGapInItsBankHistory();

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    $balanceOn = fn (string $date): ?int => $account->balances()->where('balance_date', $date)->value('balance');

    // Mar 10 is the reference: 100000. Strip its own -5000 to reach Mar 9: 105000.
    expect($balanceOn('2026-03-05'))->toBe(105000)
        // Strip Mar 5's +2000 to reach Mar 4, which is still where Feb 20 ends.
        ->and($balanceOn('2026-02-20'))->toBe(103000)
        // Strip Feb 20's -30000: the imported month is what moves the walk here.
        ->and($balanceOn('2026-02-10'))->toBe(133000)
        // Strip Feb 10's +8000.
        ->and($balanceOn('2026-01-25'))->toBe(125000);
});

test('calculateHistoricalBalances corrects a balance it derived before the gap was filled', function () {
    [$user, $account] = accountWithAGapInItsBankHistory(withImportedGap: false);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    // With February missing, the walk steps straight over it and plants Mar 4's
    // balance on Jan 25.
    expect($account->balances()->where('balance_date', '2026-01-25')->value('balance'))->toBe(103000);

    // The user imports the months the bank never sent.
    Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-20',
        'amount' => -30000,
    ]);
    Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-10',
        'amount' => 8000,
    ]);

    $service->calculateHistoricalBalances($account);

    expect($account->balances()->where('balance_date', '2026-01-25')->value('balance'))->toBe(125000)
        ->and($account->balances()->where('balance_date', '2026-02-20')->value('balance'))->toBe(103000)
        ->and($account->balances()->where('balance_date', '2026-02-10')->value('balance'))->toBe(133000);
});

test('calculateHistoricalBalances leaves a bank-reported balance alone on a re-run', function () {
    [$user, $account] = accountWithAGapInItsBankHistory(withImportedGap: false);

    // What a daily sync left behind on Mar 5 back when that was the current day.
    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-03-05',
        'balance' => 99999,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-20',
        'amount' => -30000,
    ]);

    $service->calculateHistoricalBalances($account);

    // The walk corrects its own Jan 25 but never touches the bank's Mar 5.
    expect($account->balances()->where('balance_date', '2026-03-05')->value('balance'))->toBe(99999)
        ->and($account->balances()->where('balance_date', '2026-01-25')->value('balance'))->toBe(133000);
});

test('calculateHistoricalBalances leaves a balance the user set alone on a re-run', function () {
    [$user, $account] = accountWithAGapInItsBankHistory();

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    expect($account->balances()->where('balance_date', '2026-02-20')->value('balance'))->toBe(103000);

    // The user corrects that day by hand, through the balance editor.
    actingAs($user)->postJson(route('api.accounts.balances.store', $account), [
        'balance_date' => '2026-02-20',
        'balance' => 55555,
    ])->assertCreated();

    $service->calculateHistoricalBalances($account);

    expect($account->balances()->where('balance_date', '2026-02-20')->value('balance'))->toBe(55555)
        // The days the walk does own are still rebuilt around it.
        ->and($account->balances()->where('balance_date', '2026-01-25')->value('balance'))->toBe(125000);
});

test('calculateHistoricalBalances ignores hand-entered transactions on a connected account', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-03-10',
        'balance' => 100000,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-03-10',
        'amount' => -5000,
    ]);

    // Nothing says the bank's figure ever counted this one.
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-03-05',
        'amount' => -50000,
        'source' => TransactionSource::ManuallyCreated,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-03-01',
        'amount' => 1000,
    ]);

    $service = new BalanceSyncService(Mockery::mock(BankingProviderInterface::class));
    $service->calculateHistoricalBalances($account);

    expect($account->balances()->where('balance_date', '2026-03-05')->exists())->toBeFalse()
        ->and($account->balances()->where('balance_date', '2026-03-01')->value('balance'))->toBe(105000);
});
