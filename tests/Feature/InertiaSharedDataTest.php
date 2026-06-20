<?php

use App\Enums\BankingConnectionStatus;
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

test('all pages receive app url in shared props', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('appUrl', config('app.url'))
    );
});

test('shared feature flags do not include coinbase flag', function () {
    $response = $this->withoutVite()->get(route('home'));

    $props = $response->viewData('page')['props'];

    expect($props['features'])->toBe([
        'cashflow' => true,
        'calculateBalancesOnImport' => false,
        'transactionAnalysis' => false,
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

test('authenticated users receive expired banking connection reconnect links', function () {
    $user = User::factory()->onboarded()->create();
    $expiredConnection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Santander',
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->subDay(),
    ]);
    BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Fresh Bank',
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->addDay(),
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('expiredBankingConnections', 1)
        ->where('expiredBankingConnections.0.id', $expiredConnection->id)
        ->where('expiredBankingConnections.0.aspsp_name', 'Santander')
        ->where('expiredBankingConnections.0.reconnect_url', route('open-banking.reconnect', $expiredConnection))
    );
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
});
