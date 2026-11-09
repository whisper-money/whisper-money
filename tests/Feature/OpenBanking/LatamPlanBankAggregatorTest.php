<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingSyncLogStatus;
use App\Enums\PlanFeature;
use App\Jobs\SyncAllBankingConnectionsJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\BankingSyncLog;
use App\Models\User;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.latam_pricing' => true,
    ]);

    // The price-to-lookup-key map Stripe would answer with, so nothing reaches it.
    Cache::put('stripe_lookup_keys_by_price_id', [
        'price_high_yearly' => 'whisper_pro_yearly_high',
        'price_low_monthly' => 'whisper_pro_monthly',
        'price_latam_monthly' => 'whisper_pro_latam_monthly',
    ], now()->addDay());
});

function userPaying(?string $stripePrice): User
{
    $user = User::factory()->onboarded()->create();

    if ($stripePrice !== null) {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_live_'.$user->id,
            'stripe_status' => 'active',
            'stripe_price' => $stripePrice,
            'quantity' => 1,
        ]);
    }

    return $user;
}

test('a latam subscriber has every paid feature but the bank aggregator', function () {
    $user = userPaying('price_latam_monthly');

    expect($user->canUseFeature(PlanFeature::BankAggregator))->toBeFalse()
        ->and($user->canUseFeature(PlanFeature::DirectIntegrations))->toBeTrue()
        ->and($user->canUseFeature(PlanFeature::AiSuggestions))->toBeTrue()
        ->and($user->canUseFeature(PlanFeature::McpAccess))->toBeTrue()
        ->and($user->canUseFeature(PlanFeature::ApiAccess))->toBeTrue();
});

test('a latam subscriber keeps no bank aggregator when the latam price stops being offered', function () {
    config(['subscriptions.latam_pricing' => false]);

    expect(userPaying('price_latam_monthly')->canUseFeature(PlanFeature::BankAggregator))->toBeFalse();
});

test('standard and low price subscribers keep the bank aggregator', function (string $stripePrice) {
    $user = userPaying($stripePrice);

    expect($user->canUseFeature(PlanFeature::BankAggregator))->toBeTrue()
        ->and($user->canUseFeature(PlanFeature::DirectIntegrations))->toBeTrue();
})->with(['high' => 'price_high_yearly', 'low' => 'price_low_monthly']);

test('a subscription whose tier Stripe cannot tell keeps the bank aggregator', function () {
    expect(userPaying('price_unknown')->canUseFeature(PlanFeature::BankAggregator))->toBeTrue();
});

test('free users get neither the bank aggregator nor the direct integrations', function () {
    $user = userPaying(null);

    expect($user->canUseFeature(PlanFeature::BankAggregator))->toBeFalse()
        ->and($user->canUseFeature(PlanFeature::DirectIntegrations))->toBeFalse();
});

test('a seeded latam-looking subscription never reaches Stripe and keeps the bank aggregator', function () {
    Cache::forget('stripe_lookup_keys_by_price_id');
    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_demo_1',
        'stripe_status' => 'active',
        'stripe_price' => 'price_latam_monthly',
        'quantity' => 1,
    ]);

    expect($user->canUseFeature(PlanFeature::BankAggregator))->toBeTrue()
        ->and(Cache::has('stripe_lookup_keys_by_price_id'))->toBeFalse();
});

test('a latam subscriber is sent to billing when starting a bank authorization', function () {
    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldNotReceive('startAuthorization');
    $this->app->instance(BankingProviderInterface::class, $provider);

    $this->actingAs(userPaying('price_latam_monthly'))
        ->postJson('/open-banking/authorize', ['aspsp_name' => 'Test Bank', 'country' => 'ES'])
        ->assertStatus(402)
        ->assertJson(['redirect' => route('settings.billing')]);
});

test('a latam subscriber cannot reauthorize, reconnect or sync a bank', function () {
    Queue::fake();
    $user = userPaying('price_latam_monthly');
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/open-banking/connections/{$connection->id}/reauthorize")
        ->assertStatus(402)
        ->assertJson(['redirect' => route('settings.billing')]);

    $this->actingAs($user)
        ->get("/open-banking/connections/{$connection->id}/reconnect")
        ->assertRedirect(route('settings.billing'));

    $this->actingAs($user)
        ->post("/settings/connections/{$connection->id}/sync")
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHas('error', 'Bank connections are available on the standard plan.');

    Queue::assertNotPushed(SyncBankingConnectionJob::class);
});

test('a latam subscriber can connect a direct integration', function () {
    Queue::fake();
    Bank::factory()->create(['name' => 'Indexa Capital', 'user_id' => null]);
    Http::fake([
        'api.indexacapital.com/users/me' => Http::response([
            'accounts' => [['account_number' => 'IC-001', 'status' => 'active', 'type' => 'mutual']],
        ]),
    ]);

    $this->actingAs(userPaying('price_latam_monthly'))
        ->postJson('/open-banking/indexa-capital/connect', ['api_token' => 'valid-test-token-12345'])
        ->assertOk()
        ->assertJsonStructure(['redirect_url', 'connection_id']);
});

test('a latam subscriber can sync a direct integration by hand', function () {
    Queue::fake();
    $user = userPaying('price_latam_monthly');
    $connection = BankingConnection::factory()->binance()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post("/settings/connections/{$connection->id}/sync")
        ->assertSessionHas('success');

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('a free user is still sent to the paywall from a direct integration', function () {
    $this->actingAs(userPaying(null))
        ->postJson('/open-banking/indexa-capital/connect', ['api_token' => 'valid-test-token-12345'])
        ->assertStatus(402)
        ->assertJson(['redirect' => route('subscribe')]);
});

test('the sync skips a latam subscriber\'s bank but not their direct integrations', function () {
    $user = userPaying('price_latam_monthly');
    $bank = BankingConnection::factory()->create(['user_id' => $user->id]);
    $binance = BankingConnection::factory()->binance()->create(['user_id' => $user->id]);

    $transactionSync = Mockery::mock(TransactionSyncService::class);
    $transactionSync->shouldReceive('syncTransactions')->andReturn(['synced' => 0]);
    $balanceSync = Mockery::mock(BalanceSyncService::class);
    $balanceSync->shouldReceive('syncBalances')->andReturn(['synced' => 0]);
    $balanceSync->shouldIgnoreMissing();

    runSync(new SyncBankingConnectionJob($bank), $transactionSync, $balanceSync);
    runSync(new SyncBankingConnectionJob($binance), $transactionSync, $balanceSync);

    $bankLog = BankingSyncLog::query()->where('banking_connection_id', $bank->id)->sole();
    expect($bankLog->status)->toBe(BankingSyncLogStatus::Skipped)
        ->and($bankLog->metadata['reason'])->toBe('not_on_plan')
        ->and($bank->fresh()->last_synced_at?->toIso8601String())->toBe($bank->last_synced_at?->toIso8601String());

    expect(BankingSyncLog::query()->where('banking_connection_id', $binance->id)->sole()->metadata['reason'] ?? null)
        ->not->toBe('not_on_plan');
});

test('the scheduled sweep leaves out a latam subscriber\'s banks and nothing else', function () {
    Queue::fake();
    $latam = userPaying('price_latam_monthly');
    $latamBank = BankingConnection::factory()->create(['user_id' => $latam->id, 'last_synced_at' => now()->subDay()]);
    $latamBinance = BankingConnection::factory()->binance()->create(['user_id' => $latam->id, 'last_synced_at' => now()->subDay()]);
    $standardBank = BankingConnection::factory()->create(['user_id' => userPaying('price_high_yearly')->id, 'last_synced_at' => now()->subDay()]);
    $freeBank = BankingConnection::factory()->create(['user_id' => userPaying(null)->id, 'last_synced_at' => now()->subDay()]);

    (new SyncAllBankingConnectionsJob)->handle();

    $queued = fn (BankingConnection $connection): bool => Queue::pushed(
        SyncBankingConnectionJob::class,
        fn (SyncBankingConnectionJob $job): bool => $job->bankingConnection->is($connection),
    )->isNotEmpty();

    expect($queued($latamBank))->toBeFalse()
        ->and($queued($latamBinance))->toBeTrue()
        ->and($queued($standardBank))->toBeTrue()
        ->and($queued($freeBank))->toBeTrue();
});

test('the sync keeps going for standard subscribers and free users', function (?string $stripePrice) {
    $user = userPaying($stripePrice);
    $bank = BankingConnection::factory()->create(['user_id' => $user->id]);

    runSync(new SyncBankingConnectionJob($bank), Mockery::mock(TransactionSyncService::class)->shouldIgnoreMissing(), Mockery::mock(BalanceSyncService::class)->shouldIgnoreMissing());

    expect(BankingSyncLog::query()->where('banking_connection_id', $bank->id)->sole()->metadata['reason'] ?? null)
        ->not->toBe('not_on_plan');
})->with(['standard' => 'price_high_yearly', 'free' => null]);

test('the frontend is told when banks are locked behind the standard plan', function (?string $stripePrice, bool $locked) {
    $this->actingAs(userPaying($stripePrice))
        ->get(route('settings.connections.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.bankAggregatorLocked', $locked));
})->with([
    'latam' => ['price_latam_monthly', true],
    'standard' => ['price_high_yearly', false],
    'free' => [null, false],
]);
