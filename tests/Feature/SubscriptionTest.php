<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;
use Stripe\Service\PromotionCodeService;
use Stripe\StripeClient;

beforeEach(function () {
    config([
        'landing.hide_auth_buttons' => false,
        'subscriptions.enabled' => true,
    ]);
});

test('guests cannot access subscription pages', function () {
    $this->get(route('subscribe'))->assertRedirect(route('register'));
    $this->get(route('subscribe.checkout'))->assertRedirect(route('register'));
    $this->get(route('subscribe.success'))->assertRedirect(route('register'));
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
            ->where('stats.accountsCount', 1)
            ->where('stats.transactionsCount', 3)
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

test('past due subscribed users can access protected routes during stripe retries', function () {
    $user = User::factory()->onboarded()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_past_due_test123',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_test123',
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('canceled subscribed users can use free plan without bank connections', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_canceled_test123',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_test123',
        'ends_at' => now()->subMinute(),
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('canceled subscribed users cannot use paid bank connection features', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);
    BankingConnection::factory()->for($user)->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_canceled_with_bank_test123',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_test123',
        'ends_at' => now()->subMinute(),
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('subscribe'));
});

test('canceled subscribed users with bank connections can access connections settings', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);
    BankingConnection::factory()->for($user)->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_canceled_settings_test123',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_test123',
        'ends_at' => now()->subMinute(),
    ]);

    $this->actingAs($user);

    $this->get(route('settings.connections.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/connections')
            ->has('connections', 1)
        );
});

test('paywall lets canceled subscribed users with bank connections manage connections for free plan', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);
    BankingConnection::factory()->for($user)->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_canceled_manage_connections_test123',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_test123',
        'ends_at' => now()->subMinute(),
    ]);

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canUseFreePlan', false)
            ->where('canManageConnectionsForFreePlan', true)
        );
});

test('paywall does not show manage connections option during onboarding', function () {
    $user = User::factory()->notOnboarded()->create(['paywall_seen_at' => now()]);
    BankingConnection::factory()->for($user)->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_canceled_onboarding_test123',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_test123',
        'ends_at' => now()->subMinute(),
    ]);

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canManageConnectionsForFreePlan', false)
        );
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

test('hasProPlan returns true for past due users during stripe retries', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_past_due_test123',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_test123',
    ]);

    expect($user->hasProPlan())->toBeTrue();
    expect($user->hasPastDueSubscription())->toBeTrue();
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
                ->where('price', 3.99)
                ->where('original_price', null)
                ->has('stripe_lookup_key')
                ->where('billing_period', 'month')
                ->where('trial_days', 15)
                ->has('features')
            )
            ->has('pricing.plans.yearly', fn ($plan) => $plan
                ->has('name')
                ->where('price', 23.88)
                ->where('original_price', 47.88)
                ->has('stripe_lookup_key')
                ->where('billing_period', 'year')
                ->where('trial_days', 15)
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

test('users without bank connections are redirected to paywall on first visit', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('subscribe'));
});

test('users without bank connections can access protected routes after seeing paywall', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('users with a bank connection are redirected to paywall', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->for($user)->create();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('subscribe'));
});

test('paywall shows canUseFreePlan true when no bank is connected', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canUseFreePlan', true)
        );
});

test('paywall shows canUseFreePlan false when user has a bank connection', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->for($user)->create();

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canUseFreePlan', false)
            ->where('canManageConnectionsForFreePlan', false)
        );
});

test('users with active ai consent are forced to the paywall even after seeing it', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);
    $user->recordAiConsent();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('subscribe'));
    $this->get(route('accounts.list'))->assertRedirect(route('subscribe'));
});

test('paywall shows canUseFreePlan false when user has active ai consent', function () {
    $user = User::factory()->onboarded()->create();
    $user->recordAiConsent();

    $this->actingAs($user);

    $this->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canUseFreePlan', false)
        );
});

test('subscribed users with active ai consent can access protected routes', function () {
    $user = User::factory()->onboarded()->create();
    $user->recordAiConsent();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_ai_consent_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('revoking ai consent lets users fall back to the free plan', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);
    $user->recordAiConsent();
    $user->revokeAiConsent();

    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
});

test('taxRates returns configured stripe tax rate ids', function () {
    config(['subscriptions.tax_rates' => ['txr_test_1', 'txr_test_2']]);

    $user = User::factory()->create();

    expect($user->taxRates())->toBe(['txr_test_1', 'txr_test_2']);
});

test('taxRates config defaults to the production tax rate id', function () {
    expect(config('subscriptions.tax_rates'))->toContain('txr_1TPfzrLRCmKA3oWMNWmkQeq2');
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

test('checkout applies configured trial days to the subscription builder', function () {
    config([
        'subscriptions.plans.monthly.trial_days' => 15,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'test_monthly_lookup',
    ]);
    Cache::put('stripe_price_id:test_monthly_lookup', 'price_test_monthly', now()->addHour());

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('allowPromotionCodes')->once()->andReturnSelf();
    $builder->shouldReceive('trialDays')->once()->with(15)->andReturnSelf();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('newSubscription')
        ->once()
        ->with('default', 'price_test_monthly')
        ->andReturn($builder);

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('subscribe.checkout', ['plan' => 'monthly']))->assertRedirect();
});

test('checkout tags the subscription with a valid upsell source', function () {
    config([
        'subscriptions.plans.monthly.trial_days' => 0,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'test_monthly_lookup',
    ]);
    Cache::put('stripe_price_id:test_monthly_lookup', 'price_test_monthly', now()->addHour());

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('allowPromotionCodes')->once()->andReturnSelf();
    $builder->shouldReceive('withMetadata')->once()->with(['upsell_source' => 'connections'])->andReturnSelf();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('newSubscription')
        ->once()
        ->with('default', 'price_test_monthly')
        ->andReturn($builder);

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('subscribe.checkout', ['plan' => 'monthly', 'source' => 'connections']))->assertRedirect();
});

test('checkout ignores an unknown upsell source', function () {
    config([
        'subscriptions.plans.monthly.trial_days' => 0,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'test_monthly_lookup',
    ]);
    Cache::put('stripe_price_id:test_monthly_lookup', 'price_test_monthly', now()->addHour());

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('allowPromotionCodes')->once()->andReturnSelf();
    $builder->shouldNotReceive('withMetadata');
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('newSubscription')
        ->once()
        ->with('default', 'price_test_monthly')
        ->andReturn($builder);

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('subscribe.checkout', ['plan' => 'monthly', 'source' => 'bogus']))->assertRedirect();
});

test('checkout applies lead promotion code without allowing manual promotion codes', function () {
    config([
        'subscriptions.plans.monthly.trial_days' => 0,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'test_monthly_lookup',
    ]);
    Cache::put('stripe_price_id:test_monthly_lookup', 'price_test_monthly', now()->addHour());

    $promotionCodeService = Mockery::mock(PromotionCodeService::class);
    $promotionCodeService->shouldReceive('all')
        ->once()
        ->with([
            'code' => 'WM-LEAD',
            'active' => true,
            'limit' => 1,
        ])
        ->andReturn((object) ['data' => [(object) ['id' => 'promo_lead']]]);

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->promotionCodes = $promotionCodeService;

    app()->bind(StripeClient::class, fn (): StripeClient => $stripeClient);

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withPromotionCode')->once()->with('promo_lead')->andReturnSelf();
    $builder->shouldNotReceive('allowPromotionCodes');
    $builder->shouldNotReceive('trialDays');
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('getAttribute')->with('email')->andReturn('lead@example.com');
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('newSubscription')
        ->once()
        ->with('default', 'price_test_monthly')
        ->andReturn($builder);

    UserLead::factory()->create([
        'email' => 'lead@example.com',
        'promo_code_monthly' => 'WM-LEAD',
    ]);

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('subscribe.checkout', ['plan' => 'monthly']))->assertRedirect();
});

test('checkout skips trial days when configured to zero', function () {
    config([
        'subscriptions.plans.monthly.trial_days' => 0,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'test_monthly_lookup',
    ]);
    Cache::put('stripe_price_id:test_monthly_lookup', 'price_test_monthly', now()->addHour());

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('allowPromotionCodes')->once()->andReturnSelf();
    $builder->shouldNotReceive('trialDays');
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('newSubscription')
        ->once()
        ->with('default', 'price_test_monthly')
        ->andReturn($builder);

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('subscribe.checkout', ['plan' => 'monthly']))->assertRedirect();
});
