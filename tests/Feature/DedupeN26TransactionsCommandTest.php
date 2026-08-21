<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\TransactionDescriptionFormatter;
use App\Services\Banking\TransactionFingerprint;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Support\Str;

/**
 * The pending and the settled delivery of the same N26 purchase, as they landed
 * in production: one payload each, differing only in entry_reference and in the
 * transaction code.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function n26DuplicatePayloads(): array
{
    $pending = [
        'entry_reference' => '95d1119e-92d2-11f1-a38e-4d2ca6090c88',
        'transaction_amount' => ['amount' => '13.80', 'currency' => 'EUR'],
        'credit_debit_indicator' => 'DBIT',
        'booking_date' => '2026-08-08',
        'creditor' => ['name' => 'EL QUEMAITO'],
        'bank_transaction_code' => ['code' => 'MCRD', 'sub_code' => 'UPCT'],
        'remittance_information' => ['EL QUEMAITO'],
    ];

    return [$pending, array_replace($pending, [
        'entry_reference' => '95d11175-92d2-11f1-822e-49adef1560a6',
        'bank_transaction_code' => ['code' => 'CCRD', 'sub_code' => 'POSD'],
    ])];
}

/**
 * An N26 account holding the two copies, fingerprinted the way the old code
 * did: each keyed on its own entry_reference, so they never matched.
 *
 * @return array{0: Account, 1: Transaction, 2: Transaction}
 */
function n26AccountWithDuplicate(array $pendingAttributes = [], array $settledAttributes = []): array
{
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'N26', 'user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'bank_id' => $bank->id,
    ]);

    [$pending, $settled] = n26DuplicatePayloads();

    $rows = collect([[$pending, $pendingAttributes, '-2 days'], [$settled, $settledAttributes, '-1 day']])
        ->map(fn (array $row) => Transaction::factory()->enableBanking()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'description' => 'EL QUEMAITO',
            'notes' => null,
            'transaction_date' => '2026-08-08',
            'amount' => -1380,
            'external_transaction_id' => $row[0]['entry_reference'],
            'dedup_fingerprint' => TransactionFingerprint::for($row[0]),
            'raw_data' => $row[0],
            'created_at' => now()->modify($row[2]),
            ...$row[1],
        ]));

    return [$account, $rows[0], $rows[1]];
}

test('dry run reports the duplicate without touching anything', function () {
    [, $pending, $settled] = n26AccountWithDuplicate();
    $originalFingerprint = $pending->dedup_fingerprint;

    $this->artisan('banking:dedupe-n26-transactions')
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('1 duplicate(s) would be soft-deleted')
        ->assertSuccessful();

    expect($pending->fresh()->trashed())->toBeFalse()
        ->and($settled->fresh()->trashed())->toBeFalse()
        ->and($pending->fresh()->dedup_fingerprint)->toBe($originalFingerprint);
});

test('apply soft-deletes the settled copy and realigns the survivor on the fixed fingerprint', function () {
    [$account, $pending, $settled] = n26AccountWithDuplicate();
    [$pendingPayload] = n26DuplicatePayloads();

    $this->artisan('banking:dedupe-n26-transactions', ['--apply' => true])
        ->expectsOutputToContain('1 duplicate(s) soft-deleted')
        ->assertSuccessful();

    expect($settled->fresh()->trashed())->toBeTrue()
        ->and($pending->fresh()->trashed())->toBeFalse()
        // The kept row now carries the fingerprint the fixed code reproduces
        // from either payload, so the next sync recognises both deliveries.
        ->and($pending->fresh()->dedup_fingerprint)
        ->toBe(TransactionFingerprint::for($pendingPayload, 'N26'))
        ->and($account->transactions()->count())->toBe(1);
});

test('a duplicate carrying notes on both copies is reported and left alone', function () {
    [, $pending, $settled] = n26AccountWithDuplicate(
        ['notes' => 'Dinner with Ana'],
        ['notes' => 'Split with Ana'],
    );

    $this->artisan('banking:dedupe-n26-transactions', ['--apply' => true])
        ->expectsOutputToContain('more than one carries notes or labels')
        ->assertSuccessful();

    expect($pending->fresh()->trashed())->toBeFalse()
        ->and($settled->fresh()->trashed())->toBeFalse();
});

test('the copy carrying the user\'s notes is the one that survives', function () {
    [, $pending, $settled] = n26AccountWithDuplicate([], ['notes' => 'Dinner with Ana']);

    $this->artisan('banking:dedupe-n26-transactions', ['--apply' => true])->assertSuccessful();

    expect($pending->fresh()->trashed())->toBeTrue()
        ->and($settled->fresh()->trashed())->toBeFalse()
        ->and($settled->fresh()->notes)->toBe('Dinner with Ana');
});

test('a manually categorized copy is preferred over the older one but does not block the cleanup', function () {
    // A manual category on both copies is not irreconcilable — they are the
    // same transaction — so the cleanup still runs.
    [, $pending, $settled] = n26AccountWithDuplicate([], ['category_source' => CategorySource::Manual]);

    $this->artisan('banking:dedupe-n26-transactions', ['--apply' => true])
        ->expectsOutputToContain('1 duplicate(s) soft-deleted')
        ->assertSuccessful();

    expect($pending->fresh()->trashed())->toBeTrue()
        ->and($settled->fresh()->trashed())->toBeFalse();
});

test('transactions from other banks are left untouched', function () {
    $user = User::factory()->onboarded()->create();
    $bank = Bank::factory()->create(['name' => 'BBVA', 'user_id' => $user->id]);
    $account = Account::factory()->connected()->create(['user_id' => $user->id, 'bank_id' => $bank->id]);

    [$pending, $settled] = n26DuplicatePayloads();

    foreach ([$pending, $settled] as $payload) {
        Transaction::factory()->enableBanking()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'notes' => null,
            'source' => TransactionSource::EnableBanking,
            'dedup_fingerprint' => TransactionFingerprint::for($payload),
            'raw_data' => $payload,
        ]);
    }

    $this->artisan('banking:dedupe-n26-transactions', ['--apply' => true])
        ->expectsOutputToContain('No N26 duplicates found.')
        ->assertSuccessful();

    expect($account->transactions()->count())->toBe(2);
});

test('the user filter scopes the run to that user', function () {
    [, $mine] = n26AccountWithDuplicate();
    [, $someoneElse] = n26AccountWithDuplicate();

    $this->artisan('banking:dedupe-n26-transactions', [
        '--apply' => true,
        '--user' => $mine->user->email,
    ])->assertSuccessful();

    expect($mine->account->transactions()->count())->toBe(1)
        ->and($someoneElse->account->transactions()->count())->toBe(2);
});

test('an unknown user email fails instead of running fleet-wide', function () {
    n26AccountWithDuplicate();

    $this->artisan('banking:dedupe-n26-transactions', ['--apply' => true, '--user' => 'nobody@example.com'])
        ->expectsOutputToContain('not found')
        ->assertFailed();

    expect(Transaction::count())->toBe(2);
});

test('after the cleanup the next sync recognises its own rows instead of importing a third copy', function () {
    // The whole point of realigning the survivor's fingerprint: changing the
    // formula orphans every value already stored, so without it the first sync
    // after deploy would not recognise the rows it wrote itself.
    [$account, $pending] = n26AccountWithDuplicate();
    $account->update(['external_account_id' => 'ext-n26']);
    [$pendingPayload, $settledPayload] = n26DuplicatePayloads();

    $this->artisan('banking:dedupe-n26-transactions', ['--apply' => true])->assertSuccessful();

    // N26 hands both deliveries back, each with a fresh reference, exactly as
    // it does inside the 3-day watermark overlap.
    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldReceive('getTransactions')
        ->once()
        ->andReturn([
            'transactions' => [
                array_replace($pendingPayload, ['entry_reference' => (string) Str::uuid()]),
                array_replace($settledPayload, ['entry_reference' => null]),
            ],
            'continuation_key' => null,
        ]);

    $created = (new TransactionSyncService($provider, new TransactionDescriptionFormatter))
        ->sync($account, '2026-08-06', '2026-08-10');

    expect($created)->toBe(0)
        ->and($account->transactions()->count())->toBe(1)
        ->and($account->transactions()->first()->id)->toBe($pending->id);
});
