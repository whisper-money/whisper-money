<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

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
                ->has('stripe_price_id')
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
