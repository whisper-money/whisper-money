<?php

use App\Enums\AccountType;
use App\Models\User;

beforeEach(function () {
    config(['app.demo' => [
        'email' => 'demo@whisper.money',
        'password' => 'demo',
        'encryption_key' => 'demo',
    ]]);
});

test('demo:reset creates demo user if not exists', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    expect(User::where('email', 'demo@whisper.money')->exists())->toBeTrue();
});

test('demo:reset creates 5 accounts', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    expect($user->accounts()->count())->toBe(5);

    $types = $user->accounts->pluck('type')->toArray();
    expect($types)->toContain(AccountType::Checking);
    expect($types)->toContain(AccountType::Savings);
    expect($types)->toContain(AccountType::Retirement);
    expect($types)->toContain(AccountType::Investment);
});

test('demo:reset creates 10 transactions per account', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    foreach ($user->accounts as $account) {
        expect($account->transactions()->count())->toBe(10);
    }

    expect($user->transactions()->count())->toBe(50);
});

test('demo:reset creates 3 labels', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    expect($user->labels()->count())->toBe(3);
});

test('demo:reset creates 5 automation rules', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    expect($user->automationRules()->count())->toBe(5);
});

test('demo:reset creates default categories', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    expect($user->categories()->count())->toBe(63);
});

test('demo:reset deletes existing data before recreating', function () {
    $this->artisan('demo:reset')->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    $originalAccountIds = $user->accounts->pluck('id')->toArray();

    $this->artisan('demo:reset')->assertSuccessful();

    $user->refresh();
    $newAccountIds = $user->accounts->pluck('id')->toArray();

    expect(array_intersect($originalAccountIds, $newAccountIds))->toBeEmpty();

    expect($user->accounts()->count())->toBe(5);
    expect($user->transactions()->count())->toBe(50);
});

test('demo:reset fails if demo email is not configured', function () {
    config(['app.demo.email' => null]);

    $this->artisan('demo:reset')
        ->assertFailed();
});

test('demo:reset assigns labels to transactions based on percentage', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    $transactionsWithLabels = $user->transactions()->whereHas('labels')->count();
    expect($transactionsWithLabels)->toBeGreaterThan(0);
});
