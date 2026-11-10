<?php

use App\Models\User;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function resumableSubscription(User $user, array $attributes = []): Subscription
{
    return $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_resume_'.$user->id,
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_resume_test',
        'trial_ends_at' => now()->addDays(10),
        'ends_at' => now()->addDays(10),
        ...$attributes,
    ]);
}

/**
 * Bind a Stripe client that expects the subscription update Cashier's resume()
 * sends, and records what it was sent. Nothing is expected when `$times` is 0.
 */
function bindStripeResume(int $times = 1, ?Throwable $failure = null): ArrayObject
{
    $sent = new ArrayObject;
    $subscriptions = Mockery::mock(SubscriptionService::class);
    $expectation = $subscriptions->shouldReceive('update')->times($times);

    if ($failure !== null) {
        $expectation->andThrow($failure);
    } else {
        $expectation->andReturnUsing(function (string $id, array $options) use ($sent): StripeSubscription {
            $sent['id'] = $id;
            $sent['options'] = $options;

            return StripeSubscription::constructFrom(['id' => $id, 'status' => 'trialing']);
        });
    }

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->subscriptions = $subscriptions;
    app()->bind(StripeClient::class, fn () => $stripeClient);

    return $sent;
}

test('a trial set to cancel is resumed and keeps its trial end', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_resume_trial']);
    $subscription = resumableSubscription($user);
    $sent = bindStripeResume();

    $this->actingAs($user)
        ->post(route('settings.billing.resume'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success')
        ->assertRedirect(route('settings.billing'));

    expect($sent['id'])->toBe($subscription->stripe_id)
        ->and($sent['options']['cancel_at_period_end'])->toBeFalse()
        ->and($sent['options']['trial_end'])->toBe($subscription->trial_ends_at->getTimestamp())
        ->and($subscription->fresh()->ends_at)->toBeNull()
        ->and($subscription->fresh()->onGracePeriod())->toBeFalse();
});

test('a paid plan set to cancel is resumed', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_resume_paid']);
    $subscription = resumableSubscription($user, [
        'stripe_status' => 'active',
        'trial_ends_at' => now()->subMonth(),
        'ends_at' => now()->addDays(20),
    ]);
    $sent = bindStripeResume();

    $this->actingAs($user)
        ->post(route('settings.billing.resume'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('settings.billing'));

    expect($sent['options']['trial_end'])->toBe('now')
        ->and($subscription->fresh()->ends_at)->toBeNull();
});

test('nothing is resumed when there is no scheduled cancellation', function (?array $attributes) {
    $user = User::factory()->create(['stripe_id' => 'cus_resume_none']);

    if ($attributes !== null) {
        resumableSubscription($user, $attributes);
    }

    bindStripeResume(times: 0);

    $this->actingAs($user)
        ->post(route('settings.billing.resume'))
        ->assertSessionHasErrors('subscription')
        ->assertRedirect(route('settings.billing'));
})->with([
    'no subscription' => [null],
    'running, not canceled' => [['ends_at' => null]],
    'already ended' => [['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]],
]);

test('only the signed-in user\'s own subscription can be resumed', function () {
    $owner = User::factory()->create(['stripe_id' => 'cus_resume_owner']);
    $ownersSubscription = resumableSubscription($owner);
    $intruder = User::factory()->create(['stripe_id' => 'cus_resume_intruder']);

    bindStripeResume(times: 0);

    // The route names no subscription: whatever is sent, it resumes the
    // caller's own, and this caller has none set to cancel.
    $this->actingAs($intruder)
        ->post(route('settings.billing.resume'), ['subscription' => $ownersSubscription->id])
        ->assertSessionHasErrors('subscription');

    expect($ownersSubscription->fresh()->ends_at)->not->toBeNull();
});

test('a Stripe error keeps the cancellation and tells the user', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_resume_down']);
    $subscription = resumableSubscription($user);
    bindStripeResume(failure: ApiConnectionException::factory('Stripe is down'));

    $this->actingAs($user)
        ->post(route('settings.billing.resume'))
        ->assertSessionHasErrors('subscription')
        ->assertRedirect(route('settings.billing'));

    expect($subscription->fresh()->ends_at)->not->toBeNull();
});

test('a seeded subscription is never sent to Stripe', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_resume_seeded']);
    resumableSubscription($user, ['stripe_id' => 'sub_e2e_resume']);

    bindStripeResume(times: 0);

    $this->actingAs($user)
        ->post(route('settings.billing.resume'))
        ->assertSessionHasErrors('subscription');
});

test('guests cannot resume a subscription', function () {
    $this->post(route('settings.billing.resume'))
        ->assertRedirect(route('register'));
});
