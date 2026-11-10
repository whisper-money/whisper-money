<?php

use App\Actions\Subscription\SwitchToYearly;
use App\Enums\CancellationOffer;
use App\Enums\CancellationOutcome;
use App\Enums\CancellationReason;
use App\Models\CancellationSurveyResponse;
use App\Models\User;
use App\Services\Subscriptions\StripePriceIds;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Payment;
use Laravel\Cashier\Subscription;
use Stripe\Coupon as StripeCoupon;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentIntent;
use Stripe\Service\CouponService;
use Stripe\Service\SubscriptionItemService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\SubscriptionItem as StripeSubscriptionItem;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);

    // The price-to-lookup-key map Stripe would answer with, so nothing reaches it.
    Cache::put('stripe_lookup_keys_by_price_id', [
        'price_high_monthly' => 'whisper_pro_monthly_high',
        'price_high_yearly' => 'whisper_pro_yearly_high',
        'price_latam_monthly' => 'whisper_pro_latam_monthly',
        'price_latam_yearly' => 'whisper_pro_latam_yearly',
    ], now()->addDay());
    Cache::put('stripe_price_id:whisper_pro_yearly_high', 'price_high_yearly', now()->addHour());
    Cache::put('stripe_price_id:whisper_pro_latam_yearly', 'price_latam_yearly', now()->addHour());
});

/**
 * A user with a running `default` subscription: on a trial unless the
 * attributes say otherwise, with one item so Cashier can read its period end.
 *
 * @param  array<string, mixed>  $attributes
 */
function cancellableSubscriber(array $attributes = [], array $userAttributes = []): User
{
    $user = User::factory()->onboarded()->create(['stripe_id' => 'cus_cancel_'.fake()->uuid(), ...$userAttributes]);

    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_cancel_'.fake()->bothify('########'),
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_high_monthly',
        'quantity' => 1,
        'trial_ends_at' => now()->addDays(5),
        'ends_at' => null,
        ...$attributes,
    ]);

    $subscription->items()->create([
        'stripe_id' => 'si_cancel_'.$subscription->id,
        'stripe_product' => 'prod_pro',
        'stripe_price' => $subscription->stripe_price,
        'quantity' => 1,
    ]);

    return $user;
}

function paidSubscriberAttributes(string $price = 'price_high_monthly'): array
{
    return ['stripe_status' => 'active', 'stripe_price' => $price, 'trial_ends_at' => now()->subMonth()];
}

/**
 * A Stripe client that answers the reads the flow makes and records every
 * subscription update it is sent.
 *
 * @param  list<array<string, mixed>>  $discounts  What the Stripe subscription already carries.
 */
function fakeStripeForCancellation(array $discounts = [], ?Throwable $updateFailure = null, int $periodEnd = 0): ArrayObject
{
    $sent = new ArrayObject(['updates' => []]);
    $periodEnd = $periodEnd ?: now()->addDays(20)->getTimestamp();

    $subscriptions = Mockery::mock(SubscriptionService::class);
    $subscriptions->shouldReceive('retrieve')->andReturnUsing(fn (string $id) => StripeSubscription::constructFrom([
        'id' => $id,
        'status' => 'active',
        'discounts' => $discounts,
        'items' => ['object' => 'list', 'data' => [['id' => 'si_x', 'current_period_end' => $periodEnd]]],
    ]));

    $update = $subscriptions->shouldReceive('update');

    if ($updateFailure !== null) {
        $update->andThrow($updateFailure);
    } else {
        $update->andReturnUsing(function (string $id, array $options) use ($sent): StripeSubscription {
            $sent['updates'] = [...$sent['updates'], $options];

            return StripeSubscription::constructFrom(['id' => $id, 'status' => 'active']);
        });
    }

    $items = Mockery::mock(SubscriptionItemService::class);
    $items->shouldReceive('retrieve')->andReturnUsing(fn (string $id) => StripeSubscriptionItem::constructFrom([
        'id' => $id,
        'current_period_end' => $periodEnd,
    ]));

    $coupons = Mockery::mock(CouponService::class);
    $coupons->shouldReceive('retrieve')->andReturnUsing(fn (string $id) => StripeCoupon::constructFrom([
        'id' => $id,
        'percent_off' => 30,
        'amount_off' => null,
        'duration' => 'repeating',
        'duration_in_months' => 3,
    ]));

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->subscriptions = $subscriptions;
    $stripeClient->subscriptionItems = $items;
    $stripeClient->coupons = $coupons;
    app()->bind(StripeClient::class, fn () => $stripeClient);

    return $sent;
}

function pendingResponse(User $user, CancellationReason $reason = CancellationReason::TooExpensive): CancellationSurveyResponse
{
    return CancellationSurveyResponse::factory()->for($user)->create([
        'stripe_subscription_id' => $user->subscription('default')->stripe_id,
        'reason' => $reason,
        'on_trial' => $user->subscription('default')->onTrial(),
    ]);
}

describe('the survey', function () {
    test('stores the answer and returns the offers for the reason', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes());
        fakeStripeForCancellation();

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), [
                'reason' => 'too_expensive',
                'details' => '  money is tight  ',
            ])
            ->assertCreated()
            ->assertJsonPath('offers.0.offer', 'retention_coupon')
            ->assertJsonPath('offers.0.percentOff', 30)
            ->assertJsonPath('offers.0.months', 3)
            // 30% off three monthly invoices of 8.99.
            ->assertJsonPath('offers.0.savedAmount', 8.09)
            ->assertJsonPath('offers.1.offer', 'switch_to_yearly')
            ->assertJsonPath('offers.1.price', 53.94)
            ->assertJsonPath('offers.1.savings', 53.94)
            ->assertJsonPath('onTrial', false)
            ->assertJsonMissingPath('offers.1.lookupKey');

        $response = $user->cancellationSurveyResponses()->sole();

        expect($response->reason)->toBe(CancellationReason::TooExpensive)
            ->and($response->details)->toBe('money is tight')
            ->and($response->offer_shown)->toBe(CancellationOffer::RetentionCoupon)
            ->and($response->outcome)->toBeNull()
            ->and($response->on_trial)->toBeFalse()
            ->and($response->stripe_subscription_id)->toBe($user->subscription('default')->stripe_id);
    });

    test('matches each reason to its offer', function (string $reason, array $offers) {
        $user = cancellableSubscriber();
        fakeStripeForCancellation();

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => $reason])
            ->assertCreated()
            ->assertJsonPath('offers', fn (array $sent) => array_column($sent, 'offer') === $offers);
    })->with([
        'too expensive' => ['too_expensive', ['retention_coupon', 'switch_to_yearly']],
        'missing a feature' => ['missing_feature', ['roadmap']],
        'bank problems' => ['bank_connection_problems', ['support']],
        'too complex' => ['too_complex', ['support']],
        'not using it enough' => ['not_using_enough', []],
        'just trying' => ['just_trying', []],
        'switching' => ['switching_app', []],
        'does not trust the bank' => ['dont_trust_bank', []],
        'other' => ['other', []],
    ]);

    test('quotes a trial its end date, a paid plan the end of its period', function () {
        $trial = cancellableSubscriber();
        fakeStripeForCancellation(periodEnd: now()->addDays(20)->getTimestamp());

        $this->actingAs($trial)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'other'])
            ->assertJsonPath('endsAt', $trial->subscription('default')->trial_ends_at->toIso8601String())
            ->assertJsonPath('onTrial', true);

        $paid = cancellableSubscriber(paidSubscriberAttributes());
        $periodEnd = now()->addDays(12)->startOfSecond();
        fakeStripeForCancellation(periodEnd: $periodEnd->getTimestamp());

        $this->actingAs($paid)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'other'])
            ->assertJsonPath('endsAt', $periodEnd->toIso8601String());
    });

    test('no discount is offered to someone who already had it', function () {
        $user = cancellableSubscriber([], ['retention_coupon_redeemed_at' => now()->subYear()]);
        fakeStripeForCancellation();

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'too_expensive'])
            ->assertJsonPath('offers', fn (array $sent) => array_column($sent, 'offer') === ['switch_to_yearly']);
    });

    test('nothing is offered on top of a discount the subscription already has', function () {
        $user = cancellableSubscriber();
        fakeStripeForCancellation(discounts: [['id' => 'di_founder', 'coupon' => ['id' => 'wm_founder_forever']]]);

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'too_expensive'])
            ->assertJsonPath('offers', []);
    });

    test('no discount is offered to a yearly plan that does not renew within its months', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes('price_high_yearly'));
        fakeStripeForCancellation(periodEnd: now()->addMonths(7)->getTimestamp());

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'too_expensive'])
            ->assertJsonPath('offers', []);
    });

    test('a monthly plan renewing in two months has two discounted invoices left in the window', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes());
        fakeStripeForCancellation(periodEnd: now()->addMonths(2)->subDay()->getTimestamp());

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'too_expensive'])
            // Renewals at ~2 months and ~3 months fall inside the three.
            ->assertJsonPath('offers.0.savedAmount', 5.39);
    });

    test('a yearly subscriber gets the discount saving of one invoice and no yearly switch', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes('price_high_yearly'));
        fakeStripeForCancellation();

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'too_expensive'])
            ->assertJsonCount(1, 'offers')
            ->assertJsonPath('offers.0.savedAmount', 16.18);
    });

    test('a latam monthly subscriber is offered the latam yearly price', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes('price_latam_monthly'));
        fakeStripeForCancellation();

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'too_expensive'])
            ->assertJsonPath('offers.1.price', 23.88)
            ->assertJsonPath('offers.1.savings', 24);
    });

    test('is refused without a subscription the flow can cancel', function (?array $attributes) {
        $user = $attributes === null
            ? User::factory()->onboarded()->create()
            : cancellableSubscriber($attributes);

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'other'])
            ->assertStatus(409);

        expect(CancellationSurveyResponse::query()->count())->toBe(0);
    })->with([
        'no subscription' => [null],
        'already set to cancel' => [['ends_at' => now()->addDays(5)]],
        'payment failed' => [['stripe_status' => 'incomplete']],
        'already ended' => [['stripe_status' => 'canceled', 'trial_ends_at' => now()->subMonth(), 'ends_at' => now()->subDay()]],
        'seeded' => [['stripe_id' => 'sub_e2e_cancel']],
    ]);

    test('validates the reason', function () {
        $this->actingAs(cancellableSubscriber())
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'bored', 'details' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'details']);
    });
});

describe('the retention discount', function () {
    test('is applied to the subscription and recorded', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes());
        $sent = fakeStripeForCancellation();
        $response = pendingResponse($user);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.retention-coupon', $response))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success')
            ->assertRedirect(route('settings.billing'));

        expect($sent['updates'])->toBe([['discounts' => [['coupon' => 'wm_retention_30_3m']]]])
            ->and($user->fresh()->retention_coupon_redeemed_at)->not->toBeNull()
            ->and($response->fresh()->offer_accepted)->toBe(CancellationOffer::RetentionCoupon)
            ->and($response->fresh()->outcome)->toBe(CancellationOutcome::Saved)
            ->and($user->subscription('default')->fresh()->ends_at)->toBeNull();
    });

    test('is applied only once per user', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes());
        $sent = fakeStripeForCancellation();

        $this->actingAs($user)->post(route('settings.billing.cancellation.retention-coupon', pendingResponse($user)));

        $second = pendingResponse($user);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.retention-coupon', $second))
            ->assertSessionHasErrors('cancellation');

        expect($sent['updates'])->toHaveCount(1)
            ->and($second->fresh()->outcome)->toBeNull();
    });

    test('a Stripe failure gives the discount back to the user', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes());
        fakeStripeForCancellation(updateFailure: ApiConnectionException::factory('Stripe is down'));
        $response = pendingResponse($user);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.retention-coupon', $response))
            ->assertSessionHasErrors('cancellation');

        expect($user->fresh()->retention_coupon_redeemed_at)->toBeNull()
            ->and($response->fresh()->outcome)->toBeNull();
    });
});

describe('the yearly switch', function () {
    test('moves a monthly subscriber onto the yearly price of their tier', function (string $monthly, string $yearly) {
        $user = cancellableSubscriber(paidSubscriberAttributes($monthly));
        $response = pendingResponse($user);
        fakeStripeForCancellation();

        $switch = Mockery::mock(SwitchToYearly::class, [app(StripePriceIds::class)])->makePartial();
        $switch->shouldReceive('handle')->once()->withArgs(
            fn (Subscription $subscription, array $plan) => $subscription->is($user->subscription('default'))
                && app(StripePriceIds::class)->forLookupKey($plan['stripe_lookup_key']) === $yearly,
        );
        app()->instance(SwitchToYearly::class, $switch);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.switch-to-yearly', $response))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.billing'));

        expect($response->fresh()->offer_accepted)->toBe(CancellationOffer::SwitchToYearly)
            ->and($response->fresh()->outcome)->toBe(CancellationOutcome::Saved);
    })->with([
        'standard' => ['price_high_monthly', 'price_high_yearly'],
        'latam' => ['price_latam_monthly', 'price_latam_yearly'],
    ]);

    test('invoices the swap right away', function () {
        $subscription = Mockery::mock(Subscription::class);
        $subscription->shouldReceive('swapAndInvoice')->once()->with('price_high_yearly');

        app(SwitchToYearly::class)->handle($subscription, config('subscriptions.plans.yearly'));
    });

    test('a card that needs confirming goes to the payment page', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes());
        $response = pendingResponse($user);

        $switch = Mockery::mock(SwitchToYearly::class, [app(StripePriceIds::class)])->makePartial();
        $switch->shouldReceive('handle')->once()->andThrow(
            IncompletePayment::requiresAction(new Payment(PaymentIntent::constructFrom(['id' => 'pi_yearly_3ds']))),
        );
        app()->instance(SwitchToYearly::class, $switch);

        fakeStripeForCancellation();

        // Cashier's payment page is no Inertia page: the browser has to leave for it.
        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.switch-to-yearly', $response), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('cashier.payment', ['pi_yearly_3ds', 'redirect' => route('settings.billing')]));

        expect($response->fresh()->outcome)->toBe(CancellationOutcome::Saved);
    });

    test('is refused to a yearly subscriber', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes('price_high_yearly'));
        $response = pendingResponse($user);

        $switch = Mockery::mock(SwitchToYearly::class);
        $switch->shouldNotReceive('handle');
        app()->instance(SwitchToYearly::class, $switch);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.switch-to-yearly', $response))
            ->assertSessionHasErrors('cancellation');

        expect($response->fresh()->outcome)->toBeNull();
    });
});

describe('the cancellation', function () {
    test('cancels a trial at its end, with the reason for Stripe', function () {
        $user = cancellableSubscriber();
        $sent = fakeStripeForCancellation();
        $response = pendingResponse($user, CancellationReason::NotUsingEnough);
        $response->update(['details' => 'Busy month']);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.cancel', $response))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.billing'));

        $subscription = $user->subscription('default')->fresh();

        expect($sent['updates'])->toBe([
            ['cancel_at_period_end' => true],
            // The reason only: the user's words never leave our database.
            ['cancellation_details' => ['feedback' => 'unused', 'comment' => 'not_using_enough']],
        ])
            ->and($subscription->onGracePeriod())->toBeTrue()
            ->and($subscription->ends_at->equalTo($subscription->trial_ends_at))->toBeTrue()
            ->and($response->fresh()->outcome)->toBe(CancellationOutcome::Canceled);
    });

    test('cancels a paid plan at the end of its period, not now', function () {
        $user = cancellableSubscriber(paidSubscriberAttributes());
        $periodEnd = now()->addDays(17)->startOfSecond();
        fakeStripeForCancellation(periodEnd: $periodEnd->getTimestamp());

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.cancel', pendingResponse($user)))
            ->assertRedirect(route('settings.billing'));

        $subscription = $user->subscription('default')->fresh();

        expect($subscription->ends_at->equalTo($periodEnd))->toBeTrue()
            ->and($subscription->onGracePeriod())->toBeTrue()
            ->and($subscription->stripe_status)->toBe('active');
    });

    test('a Stripe failure keeps the plan and tells the user', function () {
        $user = cancellableSubscriber();
        fakeStripeForCancellation(updateFailure: ApiConnectionException::factory('Stripe is down'));
        $response = pendingResponse($user);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.cancel', $response))
            ->assertSessionHasErrors('cancellation');

        expect($user->subscription('default')->fresh()->ends_at)->toBeNull()
            ->and($response->fresh()->outcome)->toBeNull();
    });

    test('is refused once the subscription is already set to cancel', function () {
        $user = cancellableSubscriber();
        $response = pendingResponse($user);
        $user->subscription('default')->update(['ends_at' => now()->addDays(5)]);
        fakeStripeForCancellation();

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.cancel', $response))
            ->assertStatus(409);
    });

    test('a step already running for the same answer is refused', function () {
        $user = cancellableSubscriber();
        $response = pendingResponse($user);
        $sent = fakeStripeForCancellation();
        $lock = Cache::lock("cancellation-flow:{$response->id}", 30);
        $lock->get();

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.cancel', $response))
            ->assertStatus(409);

        $lock->release();

        expect($sent['updates'])->toBe([]);
    });

    test('a flow that has ended cannot be replayed', function () {
        $user = cancellableSubscriber();
        $response = pendingResponse($user);
        $response->markCanceled();
        fakeStripeForCancellation();

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.retention-coupon', $response))
            ->assertStatus(409);
    });
});

test('another user\'s flow is unreachable', function (string $route) {
    $owner = cancellableSubscriber();
    $ownersResponse = pendingResponse($owner);
    $intruder = cancellableSubscriber();
    $sent = fakeStripeForCancellation();

    $this->actingAs($intruder)
        ->post(route($route, $ownersResponse))
        ->assertNotFound();

    expect($sent['updates'])->toBe([])
        ->and($ownersResponse->fresh()->outcome)->toBeNull();
})->with([
    'settings.billing.cancellation.retention-coupon',
    'settings.billing.cancellation.switch-to-yearly',
    'settings.billing.cancellation.cancel',
]);

test('the demo and press accounts are blocked', function (string $account) {
    config(['app.demo.email' => 'demo@whisper.money', 'app.press.email' => 'press@whisper.money']);
    $user = cancellableSubscriber([], ['email' => "{$account}@whisper.money"]);

    $this->actingAs($user)
        ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'other'])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('settings.billing.cancellation.cancel', pendingResponse($user)))
        ->assertSessionHasErrors('demo');
})->with(['demo', 'press']);

test('guests cannot start the flow', function () {
    $this->post(route('settings.billing.cancellation.store'), ['reason' => 'other'])
        ->assertRedirect(route('register'));
});

test('billing says whether the flow can run on the plan', function (array $attributes, bool $canCancel) {
    fakeStripeForCancellation();

    $this->actingAs(cancellableSubscriber($attributes))
        ->get(route('settings.billing'))
        ->assertInertia(fn ($page) => $page->where('subscription.canCancel', $canCancel));
})->with([
    'trial' => [[], true],
    'paid' => [paidSubscriberAttributes(), true],
    'already set to cancel' => [['ends_at' => now()->addDays(5)], false],
    'payment failed' => [['stripe_status' => 'past_due'], false],
]);
