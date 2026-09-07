<?php

use App\Jobs\RecalculateHistoricalBalancesJob;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

/**
 * A connected account holding one bank balance on Mar 10 and the movement that
 * produced it, so the walk has something to rebuild.
 *
 * @return array{0: User, 1: Account}
 */
function connectedAccountWithBalanceHistory(): array
{
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
        'currency_code' => 'EUR',
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-03-10',
        'balance' => 100000,
    ]);

    Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-03-01',
        'amount' => -5000,
    ]);

    return [$user, $account];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function postTransaction(User $user, Account $account, array $overrides = []): void
{
    actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'Imported row',
        'transaction_date' => '2026-02-20',
        'amount' => -30000,
        'currency_code' => 'EUR',
        'source' => 'imported',
        ...$overrides,
    ])->assertCreated();
}

test('storing a transaction dated inside the balance history queues a rebuild', function () {
    Queue::fake();

    [$user, $account] = connectedAccountWithBalanceHistory();

    postTransaction($user, $account);

    Queue::assertPushed(
        RecalculateHistoricalBalancesJob::class,
        fn (RecalculateHistoricalBalancesJob $job): bool => $job->account->is($account),
    );
});

test('storing a transaction dated past the newest balance queues nothing', function () {
    Queue::fake();

    [$user, $account] = connectedAccountWithBalanceHistory();

    postTransaction($user, $account, ['transaction_date' => '2026-03-20']);

    Queue::assertNotPushed(RecalculateHistoricalBalancesJob::class);
});

test('storing a transaction on an account with no balance history queues nothing', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-123',
        'currency_code' => 'EUR',
    ]);

    postTransaction($user, $account);

    Queue::assertNotPushed(RecalculateHistoricalBalancesJob::class);
});

test('storing a transaction on a manual account queues nothing', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-03-10',
        'balance' => 100000,
    ]);

    postTransaction($user, $account);

    Queue::assertNotPushed(RecalculateHistoricalBalancesJob::class);
});

test('an import of many rows collapses into a single queued rebuild', function () {
    Queue::fake();

    [$user, $account] = connectedAccountWithBalanceHistory();

    foreach (range(1, 5) as $day) {
        postTransaction($user, $account, ['transaction_date' => sprintf('2026-02-%02d', $day)]);
    }

    // ShouldBeUnique is what does the collapsing, and the fake honours it.
    Queue::assertPushed(RecalculateHistoricalBalancesJob::class, 1);
});

test('the job rebuilds the history the import just made answerable', function () {
    [$user, $account] = connectedAccountWithBalanceHistory();

    Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-02-20',
        'amount' => -30000,
    ]);

    (new RecalculateHistoricalBalancesJob($account))->handle(app(BalanceSyncService::class));

    // Mar 10 is the reference at 100000; Mar 1's -5000 lands the walk on 105000,
    // and Feb 20's -30000 carries it back to 135000 before that.
    expect($account->balances()->where('balance_date', '2026-03-01')->value('balance'))->toBe(100000)
        ->and($account->balances()->where('balance_date', '2026-02-20')->value('balance'))->toBe(105000);
});

test('the job does nothing for an account disconnected since it was queued', function () {
    [, $account] = connectedAccountWithBalanceHistory();

    $job = new RecalculateHistoricalBalancesJob($account);
    $account->update(['banking_connection_id' => null]);

    $job->handle(app(BalanceSyncService::class));

    expect($account->balances()->count())->toBe(1);
});
