<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

test('guests cannot access subscription pages', function () {
    $this->get(route('subscribe'))->assertRedirect(route('login'));
    $this->get(route('subscribe.checkout'))->assertRedirect(route('login'));
    $this->get(route('subscribe.success'))->assertRedirect(route('login'));
});

test('users without subscription are redirected to paywall when accessing protected routes', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('subscribe'));
    $this->get(route('accounts.list'))->assertRedirect(route('subscribe'));
    $this->get(route('transactions.index'))->assertRedirect(route('subscribe'));
});

test('users can view the paywall page', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $this->get(route('subscribe'))->assertOk();
});

test('paywall page includes user stats', function () {
    $user = User::factory()->onboarded()->create();

    $account = Account::factory()->for($user)->create(['currency_code' => 'USD']);
    AccountBalance::factory()->for($account)->create(['balance' => 150000]);
    Transaction::factory()->count(3)->for($user)->for($account)->create();
    Category::factory()->count(2)->for($user)->create();

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->has('stats')
            ->has('stats.accountsCount')
            ->has('stats.transactionsCount')
            ->has('stats.categoriesCount')
            ->has('stats.automationRulesCount')
            ->has('stats.balancesByCurrency')
            ->where('stats.accountsCount', 1)
            ->where('stats.transactionsCount', 3)
            ->where('stats.balancesByCurrency.USD', 150000)
        );
});

test('subscribed users are redirected from paywall to dashboard', function () {
    $user = User::factory()->onboarded()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $this->actingAs($user);

    $this->get(route('subscribe'))->assertRedirect(route('dashboard'));
});

test('subscribed users can access protected routes', function () {
    $user = User::factory()->onboarded()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('users can view the success page after subscribing', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $this->get(route('subscribe.success'))->assertOk();
});

test('cancel route redirects to paywall', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $this->get(route('subscribe.cancel'))->assertRedirect(route('subscribe'));
});

test('subscription middleware allows access when subscriptions are disabled', function () {
    config(['subscriptions.enabled' => false]);

    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('hasProPlan returns true when subscriptions are disabled', function () {
    config(['subscriptions.enabled' => false]);

    $user = User::factory()->create();

    expect($user->hasProPlan())->toBeTrue();
});

test('hasProPlan returns false for unsubscribed users when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    expect($user->hasProPlan())->toBeFalse();
});

test('hasProPlan returns true for subscribed users', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    expect($user->hasProPlan())->toBeTrue();
});

test('landing page passes subscriptions enabled prop when enabled', function () {
    config(['subscriptions.enabled' => true]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->has('subscriptionsEnabled')
            ->where('subscriptionsEnabled', true)
            ->has('pricing')
            ->has('pricing.plans')
            ->has('pricing.defaultPlan')
            ->has('pricing.promo')
            ->has('pricing.currency')
        );
});

test('landing page passes subscriptions enabled prop when disabled', function () {
    config(['subscriptions.enabled' => false]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->has('subscriptionsEnabled')
            ->where('subscriptionsEnabled', false)
            ->has('pricing')
        );
});

test('pricing config includes all plan details', function () {
    config(['subscriptions.enabled' => true]);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->has('pricing.plans.monthly', fn ($plan) => $plan
                ->has('name')
                ->has('price')
                ->has('original_price')
                ->has('stripe_lookup_key')
                ->has('billing_period')
                ->has('features')
            )
            ->has('pricing.promo', fn ($promo) => $promo
                ->has('enabled')
                ->has('code')
                ->has('description')
                ->has('badge')
            )
        );
});

test('open banking users without bank connections are redirected to paywall on first visit', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->activate('open-banking');

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('subscribe'));
});

test('open banking users without bank connections can access protected routes after seeing paywall', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);

    Feature::for($user)->activate('open-banking');

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('open banking users with a bank connection are redirected to paywall', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->activate('open-banking');
    BankingConnection::factory()->for($user)->create();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('subscribe'));
});

test('paywall shows canUseFreePlan true when open banking is active and no bank connected', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->activate('open-banking');

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canUseFreePlan', true)
        );
});

test('paywall shows canUseFreePlan false when open banking is active but user has a bank connection', function () {
    $user = User::factory()->onboarded()->create();

    Feature::for($user)->activate('open-banking');
    BankingConnection::factory()->for($user)->create();

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canUseFreePlan', false)
        );
});

test('paywall shows canUseFreePlan false when open banking is not active', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canUseFreePlan', false)
        );
});

test('billing portal creates stripe customer when user has no stripe id', function () {
    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('isDemoAccount')->andReturn(false);
    $user->shouldReceive('hasStripeId')->once()->andReturn(false);
    $user->shouldReceive('createAsStripeCustomer')->once();
    $user->shouldReceive('redirectToBillingPortal')
        ->with(route('settings.billing'))
        ->once()
        ->andReturn(new RedirectResponse(route('settings.billing')));

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('settings.billing.portal'))->assertRedirect();
});

test('billing portal skips stripe customer creation when user already has a stripe id', function () {
    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('isDemoAccount')->andReturn(false);
    $user->shouldReceive('hasStripeId')->once()->andReturn(true);
    $user->shouldNotReceive('createAsStripeCustomer');
    $user->shouldReceive('redirectToBillingPortal')
        ->with(route('settings.billing'))
        ->once()
        ->andReturn(new RedirectResponse(route('settings.billing')));

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('settings.billing.portal'))->assertRedirect();
});
