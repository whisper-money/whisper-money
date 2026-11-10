<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

test('guests receive null auth user in shared props', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null)
    );
});

test('authenticated users receive auth user in shared props', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Madrid']);

    $response = actingAs($user)->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $user->id)
        ->where('auth.user.email', $user->email)
        ->where('auth.user.timezone', 'Europe/Madrid')
    );
});

test('shared auth user does not expose sensitive fields', function () {
    $user = User::factory()->onboarded()->create([
        'stripe_id' => 'cus_test123',
        'pm_type' => 'card',
        'pm_last_four' => '4242',
        'trial_ends_at' => now()->addDays(7),
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.email', $user->email)
        ->missing('auth.user.stripe_id')
        ->missing('auth.user.pm_type')
        ->missing('auth.user.pm_last_four')
        ->missing('auth.user.trial_ends_at')
        ->missing('auth.user.password')
        ->missing('auth.user.two_factor_secret')
        ->missing('auth.user.two_factor_recovery_codes')
        ->missing('auth.user.remember_token')
    );
});

test('shared feature flags do not include coinbase flag', function () {
    $response = $this->withoutVite()->get(route('home'));

    $props = $response->viewData('page')['props'];

    expect($props['features'])->toBe([
        'cashflow' => true,
        'fullImport' => false,
        'fullImportSettings' => false,
    ]);
});

test('authenticated users receive subscription payment issue when subscription is past due', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_past_due_test123',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_test123',
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('subscriptionPaymentIssue.status', 'past_due')
        ->where('subscriptionPaymentIssue.action_url', route('settings.billing.portal'))
    );
});

test('authenticated users do not receive subscription payment issue when subscription is active', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_active_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('subscriptionPaymentIssue', null)
    );
});

test('bank connection issues list expired and stuck connections with a way to reconnect', function () {
    $user = User::factory()->onboarded()->create();
    $consentLapsed = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Santander',
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->subDay(),
    ]);
    $flaggedExpired = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'BBVA',
    ]);
    $outOfRetries = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'ING',
        'valid_until' => now()->addMonth(),
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1,
    ]);
    $apiKeyFailed = BankingConnection::factory()->kraken()->error()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES,
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('bankConnectionIssues', 4)
        ->where('bankConnectionIssues.0.id', $flaggedExpired->id)
        ->where('bankConnectionIssues.0.reason', 'expired')
        ->where('bankConnectionIssues.1.id', $outOfRetries->id)
        ->where('bankConnectionIssues.1.reason', 'error')
        ->where('bankConnectionIssues.1.reconnect_url', route('open-banking.reconnect', $outOfRetries))
        ->where('bankConnectionIssues.2.id', $apiKeyFailed->id)
        ->where('bankConnectionIssues.2.aspsp_name', 'Kraken')
        ->where('bankConnectionIssues.2.provider', 'kraken')
        ->where('bankConnectionIssues.2.reason', 'error')
        ->where('bankConnectionIssues.2.reconnect_url', null)
        ->where('bankConnectionIssues.3.id', $consentLapsed->id)
        ->where('bankConnectionIssues.3.aspsp_name', 'Santander')
        ->where('bankConnectionIssues.3.reason', 'expired')
        ->where('bankConnectionIssues.3.reconnect_url', route('open-banking.reconnect', $consentLapsed))
    );
});

test('an errored bank connection whose consent has also lapsed reads as expired', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'valid_until' => now()->subDay(),
    ]);

    actingAs($user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bankConnectionIssues', 1)
            ->where('bankConnectionIssues.0.reason', 'expired')
        );
});

test('bank connection issues leave out healthy, recovering, unfinished and disconnected connections', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->create([
        'user_id' => $user->id,
        'valid_until' => now()->addDay(),
    ]);
    BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'valid_until' => now()->addMonth(),
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES - 1,
    ]);
    BankingConnection::factory()->revoked()->create([
        'user_id' => $user->id,
        'valid_until' => now()->subDay(),
    ]);
    BankingConnection::factory()->pending()->create(['user_id' => $user->id]);
    BankingConnection::factory()->awaitingMapping()->create(['user_id' => $user->id]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->has('bankConnectionIssues', 0));
});

test('bank connection issues never include another user\'s connections', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->expired()->create();
    BankingConnection::factory()->error()->create([
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1,
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->has('bankConnectionIssues', 0));
});

test('authenticated users receive their banking connections in shared props', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Bankinter',
        'status' => BankingConnectionStatus::Active,
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('bankingConnections', 1)
        ->where('bankingConnections.0.id', $connection->id)
        ->where('bankingConnections.0.aspsp_name', 'Bankinter')
        ->where('bankingConnections.0.provider', $connection->provider->value)
        ->where('bankingConnections.0.status', 'active')
    );
});

test('shared currency options split profile and account currencies', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('currencies.profile.0.code', 'USD')
        ->where('currencies.accounts.0.code', 'USD')
    );

    $props = $response->viewData('page')['props'];

    expect(collect($props['currencies']['profile'])->pluck('code'))->toContain('ARS');
    expect(collect($props['currencies']['profile'])->pluck('code'))->not->toContain('BTC');
    expect(collect($props['currencies']['accounts'])->pluck('code'))->toContain('BTC');
    expect(collect($props['currencies']['profile'])->pluck('code'))->toContain('CZK');
    expect(collect($props['currencies']['accounts'])->pluck('code'))->toContain('CZK');
});

test('shares whether AI import mapping is enabled for the user', function (bool $enabled) {
    config()->set('ai_import_mapping.enabled', $enabled);
    config()->set('services.typesafe.enabled', true);

    $response = actingAs(User::factory()->onboarded()->create())->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('aiImportMappingEnabled', $enabled));
})->with([true, false]);

test('guests never get AI import mapping', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page->where('aiImportMappingEnabled', false));
});
