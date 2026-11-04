<?php

use App\Actions\Subscription\CancelSubscriptionNow;
use App\Models\User;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

function subscriptionWithStatus(User $user, string $status): Subscription
{
    return $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => "sub_{$status}_cancel_unpaid_{$user->id}",
        'stripe_status' => $status,
        'stripe_price' => 'price_cancel_unpaid_test',
        'trial_ends_at' => now()->subDays(3),
    ]);
}

function expectNoCancellation(): void
{
    $cancel = Mockery::mock(CancelSubscriptionNow::class);
    $cancel->shouldNotReceive('handle');
    app()->instance(CancelSubscriptionNow::class, $cancel);
}

test('a subscription whose payment failed can be cancelled from the billing page', function (string $status) {
    $user = User::factory()->create();
    $subscription = subscriptionWithStatus($user, $status);

    $cancel = Mockery::mock(CancelSubscriptionNow::class);
    $cancel->shouldReceive('handle')
        ->once()
        ->withArgs(fn (User $owner, Subscription $cancelled): bool => $owner->is($user) && $cancelled->is($subscription));
    app()->instance(CancelSubscriptionNow::class, $cancel);

    $this->actingAs($user)
        ->post(route('settings.billing.cancel-unpaid'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.billing'));
})->with([
    'past due' => ['past_due'],
    'unpaid' => ['unpaid'],
    'incomplete' => ['incomplete'],
]);

test('an unpaid subscription without a Stripe customer is cancelled locally', function () {
    $user = User::factory()->create(['stripe_id' => null]);
    $subscription = subscriptionWithStatus($user, 'unpaid');

    $this->actingAs($user)
        ->post(route('settings.billing.cancel-unpaid'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.billing'));

    expect($subscription->fresh())
        ->stripe_status->toBe('canceled')
        ->ends_at->not->toBeNull();
});

test('a Stripe error keeps the subscription and tells the user', function () {
    $user = User::factory()->create();
    subscriptionWithStatus($user, 'unpaid');

    $cancel = Mockery::mock(CancelSubscriptionNow::class);
    $cancel->shouldReceive('handle')->once()->andThrow(ApiConnectionException::factory('Stripe is down'));
    app()->instance(CancelSubscriptionNow::class, $cancel);

    $this->actingAs($user)
        ->post(route('settings.billing.cancel-unpaid'))
        ->assertSessionHasErrors('subscription')
        ->assertRedirect(route('settings.billing'));
});

test('nothing is cancelled when no subscription has a failed payment', function (?string $status) {
    $user = User::factory()->create();

    if ($status !== null) {
        subscriptionWithStatus($user, $status);
    }

    expectNoCancellation();

    $this->actingAs($user)
        ->post(route('settings.billing.cancel-unpaid'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.billing'));
})->with([
    'no subscription' => [null],
    'active' => ['active'],
    'trialing' => ['trialing'],
]);

test('a shared account cannot cancel the seeded subscription', function () {
    config(['app.demo.email' => 'demo@whisper.money']);

    $demo = User::factory()->create(['email' => 'demo@whisper.money']);
    subscriptionWithStatus($demo, 'unpaid');

    expectNoCancellation();

    $this->actingAs($demo)
        ->from(route('settings.billing'))
        ->post(route('settings.billing.cancel-unpaid'))
        ->assertSessionHasErrors('demo')
        ->assertRedirect(route('settings.billing'));
});

test('guests cannot cancel a subscription', function () {
    $this->post(route('settings.billing.cancel-unpaid'))
        ->assertRedirect(route('register'));
});

/**
 * Bind a Stripe client whose subscription cancel answers with the given error,
 * or succeeds when there is none.
 */
function bindStripeSubscriptionCancel(?Throwable $failure): void
{
    $subscriptionService = Mockery::mock(SubscriptionService::class);
    $expectation = $subscriptionService->shouldReceive('cancel')->once();

    if ($failure !== null) {
        $expectation->andThrow($failure);
    }

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->subscriptions = $subscriptionService;
    app()->bind(StripeClient::class, fn () => $stripeClient);
}

test('a subscription Stripe no longer has is cancelled locally', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_cancel_unpaid_missing']);
    $subscription = subscriptionWithStatus($user, 'unpaid');

    bindStripeSubscriptionCancel(InvalidRequestException::factory('No such subscription', 404, null, null, null, 'resource_missing'));

    app(CancelSubscriptionNow::class)->handle($user, $subscription);

    expect($subscription->fresh())->stripe_status->toBe('canceled');
});

test('any other Stripe refusal leaves the subscription as it was', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_cancel_unpaid_refused']);
    $subscription = subscriptionWithStatus($user, 'unpaid');

    bindStripeSubscriptionCancel(InvalidRequestException::factory('Something else', 400, null, null, null, 'parameter_invalid'));

    expect(fn () => app(CancelSubscriptionNow::class)->handle($user, $subscription))
        ->toThrow(InvalidRequestException::class);

    expect($subscription->fresh())->stripe_status->toBe('unpaid');
});

test('a subscription Stripe still has is cancelled there', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_cancel_unpaid_ok']);
    $subscription = subscriptionWithStatus($user, 'past_due');

    bindStripeSubscriptionCancel(null);

    app(CancelSubscriptionNow::class)->handle($user, $subscription);

    expect($subscription->fresh())->stripe_status->toBe('canceled');
});

test('nothing is cancelled while subscriptions are disabled', function () {
    config(['subscriptions.enabled' => false]);

    $user = User::factory()->create();
    subscriptionWithStatus($user, 'unpaid');

    expectNoCancellation();

    expect($user->unpaidSubscription())->toBeNull();

    $this->actingAs($user)
        ->post(route('settings.billing.cancel-unpaid'))
        ->assertRedirect(route('settings.billing'));
});
