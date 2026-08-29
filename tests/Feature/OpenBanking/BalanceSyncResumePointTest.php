<?php

use App\Models\Account;
use App\Models\User;
use App\Services\Banking\Sync\BalanceSyncResumePoint;

beforeEach(function () {
    $this->account = Account::factory()->connected()->create([
        'user_id' => User::factory()->onboarded()->create()->id,
    ]);
});

test('a first sync has nothing to resume from', function () {
    $this->account->balances()->create(['balance_date' => '2026-01-10', 'balance' => 100]);

    expect(BalanceSyncResumePoint::lastSyncedDate($this->account, isFirstSync: true))->toBeNull();
});

test('a later sync resumes at the last recorded balance date', function () {
    $this->account->balances()->create(['balance_date' => '2026-01-08', 'balance' => 100]);
    $this->account->balances()->create(['balance_date' => '2026-01-10', 'balance' => 200]);

    expect(BalanceSyncResumePoint::lastSyncedDate($this->account, isFirstSync: false))->toBe('2026-01-10');
});

test('a later sync on an account with no balances has nothing to resume from', function () {
    expect(BalanceSyncResumePoint::lastSyncedDate($this->account, isFirstSync: false))->toBeNull();
});

test('a windowed fetch starts the day after the last recorded balance', function () {
    $this->account->balances()->create(['balance_date' => '2026-01-10', 'balance' => 100]);

    $startDate = BalanceSyncResumePoint::startDate($this->account, isFirstSync: false, fallback: now()->subDays(180));

    expect($startDate->toDateString())->toBe('2026-01-11');
});

test('a windowed fetch falls back when there is nothing to resume from', function () {
    $fallback = now()->subDays(180);

    expect(BalanceSyncResumePoint::startDate($this->account, isFirstSync: true, fallback: $fallback))->toBe($fallback)
        ->and(BalanceSyncResumePoint::startDate($this->account, isFirstSync: false, fallback: $fallback))->toBe($fallback);
});
