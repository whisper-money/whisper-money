<?php

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

function runSoftDeletePendingEnableBankingTransactionsMigration(): void
{
    $migration = require database_path('migrations/2026_08_22_101759_soft_delete_pending_enable_banking_transactions.php');
    $migration->up();
}

function pendingTransaction(User $user, array $attributes = []): Transaction
{
    return Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'raw_data' => ['status' => 'PDNG'],
        ...$attributes,
    ]);
}

it('soft-deletes pending enable banking transactions', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-22 10:00:00'));

    $user = User::factory()->onboarded()->create();
    $pending = pendingTransaction($user);

    runSoftDeletePendingEnableBankingTransactionsMigration();

    expect($pending->fresh()->deleted_at)->not->toBeNull();
});

it('leaves settled and unknown-status transactions alone', function () {
    $user = User::factory()->onboarded()->create();
    $booked = pendingTransaction($user, ['raw_data' => ['status' => 'BOOK']]);
    $unknown = pendingTransaction($user, ['raw_data' => ['status' => 'OTHR']]);

    runSoftDeletePendingEnableBankingTransactionsMigration();

    expect($booked->fresh()->deleted_at)->toBeNull()
        ->and($unknown->fresh()->deleted_at)->toBeNull();
});

it('does not touch other sources storing a status in raw_data', function () {
    $user = User::factory()->onboarded()->create();
    $wise = Transaction::factory()->create([
        'user_id' => $user->id,
        'source' => TransactionSource::Wise,
        'raw_data' => ['status' => 'PDNG'],
    ]);

    runSoftDeletePendingEnableBankingTransactionsMigration();

    expect($wise->fresh()->deleted_at)->toBeNull();
});

it('keeps the deletion date of rows a user already binned', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-22 10:00:00'));

    $user = User::factory()->onboarded()->create();
    $binned = pendingTransaction($user);
    $binned->delete();
    $originalDeletedAt = $binned->fresh()->deleted_at;

    runSoftDeletePendingEnableBankingTransactionsMigration();

    expect($binned->fresh()->deleted_at)->toEqual($originalDeletedAt);
});
