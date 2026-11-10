<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\CancellationOffer;
use App\Enums\CancellationOutcome;
use App\Enums\CancellationReason;
use App\Enums\DripEmailType;
use App\Enums\PlanFeature;
use App\Jobs\Drip\SendSubscriptionResumingEmailJob;
use App\Listeners\SyncSubscriptionPauseFromStripe;
use App\Mail\Drip\SubscriptionResumingEmail;
use App\Models\BankingConnection;
use App\Models\CancellationSurveyResponse;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Events\WebhookHandled;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\CardException;
use Stripe\Service\SubscriptionItemService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\SubscriptionItem as StripeSubscriptionItem;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);
    $this->freezeSecond();

    // The price-to-lookup-key map Stripe would answer with, so nothing reaches it.
    Cache::put('stripe_lookup_keys_by_price_id', [
        'price_high_monthly' => 'whisper_pro_monthly_high',
        'price_high_yearly' => 'whisper_pro_yearly_high',
    ], now()->addDay());
});

/**
 * A subscriber paying the standard monthly price, past their trial.
 *
 * @param  array<string, mixed>  $attributes
 */
function pausableSubscriber(array $attributes = []): User
{
    $user = User::factory()->onboarded()->create(['stripe_id' => 'cus_pause_'.fake()->uuid()]);

    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_pause_'.fake()->bothify('########'),
        'stripe_status' => 'active',
        'stripe_price' => 'price_high_monthly',
        'quantity' => 1,
        'trial_ends_at' => now()->subMonth(),
        'ends_at' => null,
        ...$attributes,
    ]);

    $subscription->items()->create([
        'stripe_id' => 'si_pause_'.$subscription->id,
        'stripe_product' => 'prod_pro',
        'stripe_price' => $subscription->stripe_price,
        'quantity' => 1,
    ]);

    return $user;
}

/**
 * A subscriber inside (or before, or after) a pause, by the window alone.
 */
function pausedSubscriber(?CarbonImmutable $startsAt = null, ?CarbonImmutable $resumesAt = null): User
{
    return pausableSubscriber([
        'pause_starts_at' => $startsAt ?? now()->subDays(5),
        'pause_resumes_at' => $resumesAt ?? now()->addDays(20),
    ]);
}

/**
 * A Stripe client that answers the reads the flow makes and records every
 * subscription update and cancellation it is sent.
 *
 * @param  array<string, mixed>|null  $pauseCollection  What Stripe already has set.
 */
function fakeStripeForPause(
    int $periodEnd = 0,
    ?array $pauseCollection = null,
    ?Throwable $updateFailure = null,
    ?int $billingCycleAnchor = null,
    array $discounts = [],
): ArrayObject {
    $sent = new ArrayObject(['updates' => [], 'cancels' => 0]);
    $periodEnd = $periodEnd ?: now()->addDays(10)->getTimestamp();

    $subscriptions = Mockery::mock(SubscriptionService::class);
    $subscriptions->shouldReceive('retrieve')->andReturnUsing(fn (string $id) => StripeSubscription::constructFrom([
        'id' => $id,
        'status' => 'active',
        'discounts' => $discounts,
        'pause_collection' => $pauseCollection,
        'billing_cycle_anchor' => $billingCycleAnchor ?? $periodEnd,
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

    $subscriptions->shouldReceive('cancel')->andReturnUsing(function (string $id) use ($sent): StripeSubscription {
        $sent['cancels']++;

        return StripeSubscription::constructFrom(['id' => $id, 'status' => 'canceled']);
    });

    $items = Mockery::mock(SubscriptionItemService::class);
    $items->shouldReceive('retrieve')->andReturnUsing(fn (string $id) => StripeSubscriptionItem::constructFrom([
        'id' => $id,
        'current_period_end' => $periodEnd,
    ]));

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->subscriptions = $subscriptions;
    $stripeClient->subscriptionItems = $items;
    app()->bind(StripeClient::class, fn () => $stripeClient);

    return $sent;
}

function openAnswer(User $user, CancellationReason $reason = CancellationReason::NotUsingEnough): CancellationSurveyResponse
{
    return CancellationSurveyResponse::factory()->for($user)->create([
        'stripe_subscription_id' => $user->subscription('default')->stripe_id,
        'reason' => $reason,
        'on_trial' => false,
    ]);
}

/**
 * A `customer.subscription.updated` event, with Stripe answering the re-read
 * with the given pause. The event's own payload is deliberately stale.
 */
function pauseWebhook(string $stripeId, ?array $pauseCollection, int $periodEnd = 0): void
{
    fakeStripeForPause(periodEnd: $periodEnd, pauseCollection: $pauseCollection);

    app(SyncSubscriptionPauseFromStripe::class)->handle(new WebhookHandled([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['id' => $stripeId, 'pause_collection' => null]],
    ]));
}

describe('the pause offer', function () {
    test('is what "not using it enough" gets on a paid monthly plan, from the end of the paid period', function () {
        $user = pausableSubscriber();
        $periodEnd = now()->addDays(10)->toImmutable();
        fakeStripeForPause(periodEnd: $periodEnd->getTimestamp());

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'not_using_enough'])
            ->assertCreated()
            ->assertJsonPath('offers.0.offer', 'pause')
            ->assertJsonPath('offers.0.startsAt', $periodEnd->toIso8601String())
            ->assertJsonPath('offers.0.price', 8.99)
            ->assertJsonPath('offers.0.options.0.months', 1)
            ->assertJsonPath('offers.0.options.0.resumesAt', $periodEnd->addMonthNoOverflow()->toIso8601String())
            ->assertJsonPath('offers.0.options.2.months', 3)
            ->assertJsonCount(1, 'offers')
            ->assertJsonPath('paused', false);

        expect($user->cancellationSurveyResponses()->sole()->offer_shown)->toBe(CancellationOffer::Pause);
    });

    test('is not offered for "just trying it out"', function () {
        $user = pausableSubscriber();
        fakeStripeForPause();

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'just_trying'])
            ->assertJsonPath('offers', []);
    });

    test('is not offered when there is nothing to pause', function (Closure $makeUser, ?array $pauseCollection) {
        $user = $makeUser();
        fakeStripeForPause(pauseCollection: $pauseCollection);

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'not_using_enough'])
            ->assertCreated()
            ->assertJsonPath('offers', []);
    })->with([
        'a trial' => [fn () => pausableSubscriber(['stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(5)]), null],
        'a yearly plan' => [fn () => pausableSubscriber(['stripe_price' => 'price_high_yearly']), null],
        'a pause still to come' => [fn () => pausedSubscriber(now()->addDays(3)->toImmutable(), now()->addMonth()->toImmutable()), null],
        'a pause set in Stripe' => [fn () => pausableSubscriber(), ['behavior' => 'void', 'resumes_at' => now()->addMonth()->getTimestamp()]],
    ]);

    test('is not offered over a discount, whose months a pause would spend', function () {
        $user = pausableSubscriber();
        fakeStripeForPause(discounts: [['id' => 'di_founder']]);

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'not_using_enough'])
            ->assertJsonPath('offers', []);
    });

    test('resumes on the billing anchor day, as Stripe renews', function () {
        $this->travelTo(CarbonImmutable::parse('2027-02-20 08:00', 'UTC'));
        // Travelling expired the cached price map the suite set up.
        Cache::put('stripe_lookup_keys_by_price_id', ['price_high_monthly' => 'whisper_pro_monthly_high'], now()->addDay());
        $user = pausableSubscriber();
        // Anchored on January 31: the period ends on February 28, and Stripe
        // renews on March 31 and April 30, not March 28.
        fakeStripeForPause(
            periodEnd: CarbonImmutable::parse('2027-02-28 14:30', 'UTC')->getTimestamp(),
            billingCycleAnchor: CarbonImmutable::parse('2027-01-31 14:30', 'UTC')->getTimestamp(),
        );

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'not_using_enough'])
            ->assertJsonPath('offers.0.options.0.resumesAt', '2027-03-31T14:30:00+00:00')
            ->assertJsonPath('offers.0.options.1.resumesAt', '2027-04-30T14:30:00+00:00')
            ->assertJsonPath('offers.0.options.2.resumesAt', '2027-05-31T14:30:00+00:00');
    });

    test('the cancel flow does not run on a plan in its grace period or past due', function (array $attributes) {
        $user = pausableSubscriber($attributes);
        fakeStripeForPause();

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'not_using_enough'])
            ->assertConflict();
    })->with([
        'grace period' => [['ends_at' => now()->addDays(10)]],
        'past due' => [['stripe_status' => 'past_due']],
    ]);

    test('nothing is offered to a paused plan, whose invoices Stripe voids', function () {
        $user = pausedSubscriber();
        fakeStripeForPause(pauseCollection: ['behavior' => 'void', 'resumes_at' => now()->addDays(20)->getTimestamp()]);

        $this->actingAs($user)
            ->postJson(route('settings.billing.cancellation.store'), ['reason' => 'too_expensive'])
            ->assertCreated()
            ->assertJsonPath('offers', [])
            ->assertJsonPath('paused', true)
            ->assertJsonPath('endsAt', now()->toIso8601String());
    });
});

describe('taking the pause', function () {
    test('pauses collection in Stripe from the end of the paid period, and records the save', function (int $months) {
        $user = pausableSubscriber();
        $periodEnd = now()->addDays(10)->toImmutable();
        $sent = fakeStripeForPause(periodEnd: $periodEnd->getTimestamp());
        $answer = openAnswer($user);
        $resumesAt = $periodEnd->addMonthsNoOverflow($months);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.pause', $answer), ['months' => $months])
            ->assertRedirect(route('settings.billing'))
            ->assertSessionHas('success');

        // Stripe collects again an hour before the renewal, so it is charged.
        expect($sent['updates'])->toBe([[
            'pause_collection' => ['behavior' => 'void', 'resumes_at' => $resumesAt->subHour()->getTimestamp()],
        ]]);

        $subscription = $user->subscription('default')->refresh();

        expect($subscription->pause_starts_at->equalTo($periodEnd))->toBeTrue()
            ->and($subscription->pause_resumes_at->equalTo($resumesAt))->toBeTrue();

        $answer->refresh();

        expect($answer->outcome)->toBe(CancellationOutcome::Saved)
            ->and($answer->offer_accepted)->toBe(CancellationOffer::Pause);
    })->with([1, 2, 3]);

    test('refuses any other length', function (mixed $months) {
        $user = pausableSubscriber();
        $sent = fakeStripeForPause();

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.pause', openAnswer($user)), ['months' => $months])
            ->assertSessionHasErrors('months');

        expect($sent['updates'])->toBe([]);
    })->with([0, 4, 12, 'one', null]);

    test('refuses a plan that can no longer be paused', function () {
        $user = pausableSubscriber(['stripe_price' => 'price_high_yearly']);
        $sent = fakeStripeForPause();
        $answer = openAnswer($user);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.pause', $answer), ['months' => 1])
            ->assertSessionHasErrors('cancellation');

        expect($sent['updates'])->toBe([])
            ->and($answer->refresh()->outcome)->toBeNull();
    });

    test('a Stripe failure leaves the plan and the answer as they were', function () {
        $user = pausableSubscriber();
        fakeStripeForPause(updateFailure: new ApiConnectionException('down'));
        $answer = openAnswer($user);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.pause', $answer), ['months' => 1])
            ->assertSessionHasErrors('cancellation');

        expect($user->subscription('default')->refresh()->pause_starts_at)->toBeNull()
            ->and($answer->refresh()->outcome)->toBeNull();
    });

    test('is blocked on the demo account', function () {
        $user = pausableSubscriber();
        $user->update(['email' => config('app.demo.email')]);
        $sent = fakeStripeForPause();

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.pause', openAnswer($user)), ['months' => 1]);

        expect($sent['updates'])->toBe([]);
    });
});

describe('access while paused', function () {
    test('the plan stays on until the pause starts', function () {
        $user = pausedSubscriber(now()->addDays(3)->toImmutable(), now()->addMonth()->toImmutable());

        expect($user->hasProPlan())->toBeTrue()
            ->and($user->hasPausedSubscription())->toBeFalse()
            ->and($user->canUseFeature(PlanFeature::BankAggregator))->toBeTrue();
    });

    test('there is no plan inside the pause, and it comes back once the pause ends', function () {
        $user = pausedSubscriber();

        expect($user->subscribed('default'))->toBeTrue()
            ->and($user->hasProPlan())->toBeFalse()
            ->and($user->hasPausedSubscription())->toBeTrue()
            ->and($user->canUseFeature(PlanFeature::BankAggregator))->toBeFalse()
            ->and($user->canUseFeature(PlanFeature::AiSuggestions))->toBeFalse();

        $this->travel(21)->days();
        $user->unsetRelation('subscriptions');

        expect($user->hasProPlan())->toBeTrue()
            ->and($user->hasPausedSubscription())->toBeFalse();
    });

    test('a pause with no end lasts until it is lifted', function () {
        $user = pausableSubscriber(['pause_starts_at' => now()->subDay(), 'pause_resumes_at' => null]);

        expect($user->hasProPlan())->toBeFalse();
    });

    test('the gated pages send a paused user to the paused screen, not the checkout', function () {
        $user = pausedSubscriber();
        BankingConnection::factory()->for($user)->create();
        fakeStripeForPause();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('subscribe'));

        $this->actingAs($user)
            ->get(route('subscribe'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('subscription/paused')
                ->where('pause.active', true)
                ->where('pause.resumesAt', $user->subscription('default')->pause_resumes_at->toIso8601String())
                ->where('pause.plan.price', 8.99)
                ->where('auth.hasProPlan', false)
                ->where('auth.pausedSubscription.resumesAt', $user->subscription('default')->pause_resumes_at->toIso8601String()));
    });

    test('every gated page says paused, even with nothing connected', function () {
        $user = pausedSubscriber();
        $user->forceFill(['paywall_seen_at' => now()->subMonths(2)])->save();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('subscribe'));
    });

    test('a paused user is not a free user to the free-user mail', function () {
        $user = pausedSubscriber();

        expect($user->isOnFreePlan())->toBeFalse()
            ->and(pausableSubscriber()->isOnFreePlan())->toBeFalse()
            ->and(User::factory()->create()->isOnFreePlan())->toBeTrue();
    });

    test('a paused user is never offered the way down to the free plan', function () {
        $user = pausedSubscriber();
        $user->forceFill(['onboarded_at' => now()->subMonths(3)])->save();
        BankingConnection::factory()->for($user)->create();

        $this->actingAs($user)->get(route('subscribe.free-plan.confirm'))->assertRedirect(route('subscribe'));
        $this->actingAs($user)->post(route('subscribe.free-plan'))->assertForbidden();

        expect($user->bankingConnections()->count())->toBe(1);
    });

    test('the billing page shows the pause, running or still to come', function (bool $running) {
        $user = $running
            ? pausedSubscriber()
            : pausedSubscriber(now()->addDays(3)->toImmutable(), now()->addMonth()->toImmutable());
        fakeStripeForPause();

        $this->actingAs($user)
            ->get(route('settings.billing'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/billing')
                ->where('subscription.pause.active', $running)
                ->where('auth.hasProPlan', ! $running));
    })->with(['running' => true, 'still to come' => false]);

    test('a paused owner\'s banks are not synced, and come back once the pause ends', function () {
        $user = pausedSubscriber();
        $connection = BankingConnection::factory()->for($user)->create();

        expect($connection->isOffOwnersPlan())->toBeTrue();

        $this->travel(21)->days();
        $connection->unsetRelation('user');

        expect($connection->fresh()->isOffOwnersPlan())->toBeFalse();
    });

    test('the month-end sweep leaves a paused user\'s banks alone', function () {
        Mail::fake();
        $user = pausedSubscriber();
        $connection = BankingConnection::factory()->for($user)->create(['created_at' => now()->subDays(2)]);

        $provider = Mockery::mock(BankingProviderInterface::class);
        $provider->shouldNotReceive('revokeSession');
        app()->instance(BankingProviderInterface::class, $provider);

        $this->artisan('banking:cancel-free-enablebanking')
            ->expectsOutputToContain('Revoked 0 Enable Banking connection(s). Skipped paid users: 1.')
            ->assertSuccessful();

        expect($connection->fresh()->trashed())->toBeFalse();
    });
});

describe('syncing from Stripe', function () {
    test('a pause set elsewhere starts at the end of the current period', function () {
        $user = pausableSubscriber();
        $subscription = $user->subscription('default');
        $periodEnd = now()->addDays(10)->toImmutable();
        $resumesAt = now()->addMonths(2)->toImmutable();

        pauseWebhook($subscription->stripe_id, ['behavior' => 'void', 'resumes_at' => $resumesAt->getTimestamp()], $periodEnd->getTimestamp());

        $subscription->refresh();

        expect($subscription->pause_starts_at->equalTo($periodEnd))->toBeTrue()
            ->and($subscription->pause_resumes_at->equalTo($resumesAt))->toBeTrue();
    });

    test('a pause the app set is kept as the app stored it', function () {
        $startsAt = now()->addDays(10)->toImmutable();
        $resumesAt = $startsAt->addMonth();
        $user = pausedSubscriber($startsAt, $resumesAt);
        $subscription = $user->subscription('default');

        pauseWebhook($subscription->stripe_id, ['behavior' => 'void', 'resumes_at' => $resumesAt->subHour()->getTimestamp()]);

        $subscription->refresh();

        expect($subscription->pause_starts_at->equalTo($startsAt))->toBeTrue()
            ->and($subscription->pause_resumes_at->equalTo($resumesAt))->toBeTrue();
    });

    test('an old event cannot lift a pause Stripe still has', function () {
        $user = pausedSubscriber();
        $subscription = $user->subscription('default');
        $resumesAt = $subscription->pause_resumes_at;

        // The payload says no pause; Stripe, asked again, says there is one.
        pauseWebhook($subscription->stripe_id, ['behavior' => 'void', 'resumes_at' => $resumesAt->copy()->subHour()->getTimestamp()]);

        expect($user->fresh()->hasPausedSubscription())->toBeTrue();
    });

    test('a pause set after one that ended starts afresh', function () {
        $user = pausedSubscriber(now()->subMonths(3)->toImmutable(), now()->subMonth()->toImmutable());
        $subscription = $user->subscription('default');
        $periodEnd = now()->addDays(10)->toImmutable();

        pauseWebhook($subscription->stripe_id, ['behavior' => 'void', 'resumes_at' => now()->addMonths(2)->getTimestamp()], $periodEnd->getTimestamp());

        expect($subscription->refresh()->pause_starts_at->equalTo($periodEnd))->toBeTrue();
    });

    test('a pause moved elsewhere keeps the start the app gave it', function () {
        $startsAt = now()->subDays(5)->toImmutable();
        $user = pausedSubscriber($startsAt, now()->addDays(20)->toImmutable());
        $subscription = $user->subscription('default');
        $newEnd = now()->addDays(50)->toImmutable();

        pauseWebhook($subscription->stripe_id, ['behavior' => 'void', 'resumes_at' => $newEnd->getTimestamp()]);

        $subscription->refresh();

        expect($subscription->pause_starts_at->equalTo($startsAt))->toBeTrue()
            ->and($subscription->pause_resumes_at->equalTo($newEnd))->toBeTrue();
    });

    test('a pause lifted in Stripe (or ended on its own) is cleared', function () {
        $user = pausedSubscriber();
        $subscription = $user->subscription('default');

        pauseWebhook($subscription->stripe_id, null);

        $subscription->refresh();

        expect($subscription->pause_starts_at)->toBeNull()
            ->and($subscription->pause_resumes_at)->toBeNull()
            ->and($user->fresh()->hasProPlan())->toBeTrue();
    });
});

describe('resuming early', function () {
    test('inside the pause restarts the billing cycle today, charged now', function () {
        $user = pausedSubscriber();
        $sent = fakeStripeForPause();

        $this->actingAs($user)
            ->from(route('settings.billing'))
            ->post(route('settings.billing.pause.resume'))
            ->assertRedirect(route('settings.billing'))
            ->assertSessionHas('success');

        expect($sent['updates'])->toBe([[
            'pause_collection' => '',
            'billing_cycle_anchor' => 'now',
            'proration_behavior' => 'none',
            'payment_behavior' => 'error_if_incomplete',
        ]]);

        expect($user->subscription('default')->refresh()->pause_starts_at)->toBeNull()
            ->and($user->fresh()->hasProPlan())->toBeTrue();
    });

    test('before the pause starts only drops it, with nothing charged', function () {
        $user = pausedSubscriber(now()->addDays(3)->toImmutable(), now()->addMonth()->toImmutable());
        $sent = fakeStripeForPause();

        $this->actingAs($user)->post(route('settings.billing.pause.resume'))->assertSessionHas('success');

        expect($sent['updates'])->toBe([['pause_collection' => '']])
            ->and($user->subscription('default')->refresh()->pause_resumes_at)->toBeNull();
    });

    test('a declined card leaves the plan paused', function () {
        $user = pausedSubscriber();
        fakeStripeForPause(updateFailure: CardException::factory('Your card was declined.', 402));

        $this->actingAs($user)
            ->post(route('settings.billing.pause.resume'))
            ->assertSessionHasErrors('pause');

        expect($user->fresh()->hasPausedSubscription())->toBeTrue();
    });

    test('from the paused screen, the confirmation follows the user to the dashboard', function () {
        $user = pausedSubscriber();
        fakeStripeForPause();

        $this->actingAs($user)
            ->from(route('subscribe'))
            ->post(route('settings.billing.pause.resume'))
            ->assertRedirect(route('subscribe'));

        $this->actingAs($user)->get(route('subscribe'))->assertRedirect(route('dashboard'));
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('flash.success', 'Welcome back: Standard is on again.'));
    });

    test('there is nothing to resume without a pause', function () {
        $user = pausableSubscriber();
        $sent = fakeStripeForPause();

        $this->actingAs($user)->post(route('settings.billing.pause.resume'))->assertSessionHasErrors('pause');

        expect($sent['updates'])->toBe([]);
    });
});

describe('cancelling while paused', function () {
    test('ends the plan today, since nothing was paid for the month running', function () {
        $user = pausedSubscriber();
        $sent = fakeStripeForPause();
        $answer = openAnswer($user, CancellationReason::Other);

        $this->actingAs($user)
            ->post(route('settings.billing.cancellation.cancel', $answer))
            ->assertRedirect(route('settings.billing'));

        expect($sent['cancels'])->toBe(1)
            ->and($sent['updates'][0])->toHaveKey('cancellation_details')
            ->and($user->subscription('default')->refresh()->ended())->toBeTrue()
            ->and($user->subscription('default')->pause_starts_at)->toBeNull()
            ->and($answer->refresh()->outcome)->toBe(CancellationOutcome::Canceled);
    });
});

describe('the heads-up email', function () {
    test('is queued for a pause ending within three days, with the date and amount', function () {
        Bus::fake();
        $resumesAt = now()->addDays(2)->toImmutable();
        $user = pausedSubscriber(resumesAt: $resumesAt);

        $this->artisan('email:subscription-resuming')->assertSuccessful();

        Bus::assertDispatched(SendSubscriptionResumingEmailJob::class, fn (SendSubscriptionResumingEmailJob $job): bool => $job->user->is($user)
            && $job->resumesAt->equalTo($resumesAt)
            && $job->amountInCents === 899
            && $job->currency === 'eur');
    });

    test('leaves alone the pauses that end later, already ended, or are set to cancel', function () {
        Bus::fake();
        pausedSubscriber(resumesAt: now()->addDays(4)->toImmutable());
        pausedSubscriber(now()->subMonth()->toImmutable(), now()->subDay()->toImmutable());
        pausableSubscriber([
            'pause_starts_at' => now()->subDays(5),
            'pause_resumes_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2),
        ]);

        $this->artisan('email:subscription-resuming')->assertSuccessful();

        Bus::assertNotDispatched(SendSubscriptionResumingEmailJob::class);
    });

    test('goes out once per pause, however many days the command runs', function () {
        Mail::fake();
        $user = pausedSubscriber(resumesAt: now()->addDays(3)->toImmutable());

        $this->artisan('email:subscription-resuming')->assertSuccessful();
        $this->travel(1)->day();
        $this->artisan('email:subscription-resuming')->assertSuccessful();

        Mail::assertQueuedCount(1);
        Mail::assertQueued(SubscriptionResumingEmail::class, fn (SubscriptionResumingEmail $mail): bool => $mail->hasTo($user->email));
        expect($user->mailLogs()->where('email_type', DripEmailType::SubscriptionResuming)->count())->toBe(1);
    });

    test('is not sent when the pause was taken back before the queue got to it', function () {
        Mail::fake();
        $resumesAt = now()->addDays(2)->toImmutable();
        $user = pausedSubscriber(resumesAt: $resumesAt);
        $subscription = $user->subscription('default');
        $job = new SendSubscriptionResumingEmailJob($user, $subscription, $resumesAt, 899, 'eur');

        $subscription->forceFill(['pause_starts_at' => null, 'pause_resumes_at' => null])->save();
        $job->handle();

        Mail::assertNothingQueued();
    });

    test('respects the drip switch', function () {
        Bus::fake();
        config(['mail.drip_emails_enabled' => false]);
        pausedSubscriber(resumesAt: now()->addDays(2)->toImmutable());

        $this->artisan('email:subscription-resuming')->assertSuccessful();

        Bus::assertNotDispatched(SendSubscriptionResumingEmailJob::class);
    });

    test('renders the date and the amount', function () {
        $user = pausedSubscriber();
        $mail = new SubscriptionResumingEmail($user, CarbonImmutable::parse('2026-12-14 10:00', 'UTC'), 899, 'eur');

        $mail->assertSeeInHtml('December 14, 2026')
            ->assertSeeInHtml('€8.99')
            ->assertSeeInHtml(route('settings.billing'));
    });
});

test('the model reads the window', function () {
    $subscription = new Subscription([
        'pause_starts_at' => now()->subDay(),
        'pause_resumes_at' => now()->addDay(),
    ]);

    expect($subscription->isPaused())->toBeTrue()
        ->and($subscription->hasScheduledPause())->toBeFalse()
        ->and($subscription->hasPause())->toBeTrue();
});
