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
    expect($user->accounts()->count())->toBe(6);

    $types = $user->accounts->pluck('type')->toArray();
    expect($types)->toContain(AccountType::Checking);
    expect($types)->toContain(AccountType::Savings);
    expect($types)->toContain(AccountType::Retirement);
    expect($types)->toContain(AccountType::Investment);
});

test('demo:reset creates 12 months of transactions per account', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    foreach ($user->accounts as $account) {
        if ($account->type === AccountType::Investment || $account->type === AccountType::Retirement) {
            continue;
        }

        expect($account->transactions()->count())->toBeGreaterThan(100);
    }

    expect($user->transactions()->count())->toBeGreaterThan(2000);
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
    $originalTransactionCount = $user->transactions()->count();

    $this->artisan('demo:reset')->assertSuccessful();

    $user->refresh();
    $newAccountIds = $user->accounts->pluck('id')->toArray();

    expect(array_intersect($originalAccountIds, $newAccountIds))->toBeEmpty();

    expect($user->accounts()->count())->toBe(6);
    expect($user->transactions()->count())->toBeGreaterThan(2000);
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

test('demo:reset creates an active subscription', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    expect($user->subscriptions()->count())->toBe(1);
    expect($user->subscribed('default'))->toBeTrue();
    expect($user->hasProPlan())->toBeTrue();
});

test('demo:reset creates 12 months of balance history for all accounts', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    foreach ($user->accounts as $account) {
        expect($account->balances()->count())->toBe(13);
        expect($account->balances()->first()->balance)->toBeGreaterThan(0);

        $balances = $account->balances()->pluck('balance')->toArray();
        foreach ($balances as $balance) {
            $cents = $balance % 100;
            expect($cents)->toBeGreaterThanOrEqual(0);
        }
    }
});

test('demo:reset creates balance history with at least 5% growth over the year', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    $minGrowthPercentage = 0.05;

    foreach ($user->accounts as $account) {
        $balances = $account->balances()
            ->orderBy('balance_date', 'desc')
            ->pluck('balance')
            ->toArray();

        $currentBalance = $balances[0];
        $oldestBalance = $balances[12];

        $growth = ($currentBalance - $oldestBalance) / $oldestBalance;
        expect($growth)->toBeGreaterThanOrEqual($minGrowthPercentage);
    }
});

test('demo:reset assigns categories to all transactions', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    $transactionsWithoutCategory = $user->transactions()->whereNull('category_id')->count();
    expect($transactionsWithoutCategory)->toBe(0);
});

test('demo:reset assigns accounts to all transactions', function () {
    $this->artisan('demo:reset')
        ->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();

    $transactionsWithoutAccount = $user->transactions()->whereNull('account_id')->count();
    expect($transactionsWithoutAccount)->toBe(0);
});

test('demo:reset creates encrypted message that can be decrypted', function () {
    $this->artisan('demo:reset')->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    $encryptedMessage = $user->encryptedMessage;

    expect($encryptedMessage)->not->toBeNull();
    expect($encryptedMessage->encrypted_content)->not->toBeEmpty();
    expect($encryptedMessage->iv)->not->toBeEmpty();

    $service = new \App\Services\Demo\DemoEncryptionService;
    $key = $service->deriveKey('demo', $user->encryption_salt);

    $decrypted = $service->decrypt($encryptedMessage->encrypted_content, $key, $encryptedMessage->iv);
    expect($decrypted)->toBe('Hello, world');
});

test('demo:reset encrypts account names correctly', function () {
    $this->artisan('demo:reset')->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    $service = new \App\Services\Demo\DemoEncryptionService;
    $key = $service->deriveKey('demo', $user->encryption_salt);

    $account = $user->accounts()->first();
    $decryptedName = $service->decrypt($account->name, $key, $account->name_iv);

    expect($decryptedName)->toBeIn([
        'Primary Checking',
        'Joint Checking',
        'Emergency Fund',
        '401(k) Retirement',
        'Brokerage Account',
    ]);
});

test('demo:reset encrypts transaction descriptions correctly', function () {
    $this->artisan('demo:reset')->assertSuccessful();

    $user = User::where('email', 'demo@whisper.money')->first();
    $service = new \App\Services\Demo\DemoEncryptionService;
    $key = $service->deriveKey('demo', $user->encryption_salt);

    $transaction = $user->transactions()->first();
    $decryptedDescription = $service->decrypt($transaction->description, $key, $transaction->description_iv);

    expect($decryptedDescription)->not->toBeEmpty();
});
