<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;

/**
 * The money path of the pay-now redesign: the plan is bought before the thing
 * it pays for is switched on, the AI consent the gate disclosed rides along
 * with the purchase, and the user lands back in the step that sent them.
 */

/** Where the checkout parks the step to come back to. Mirrors the controller. */
const RETURN_KEY = 'subscription.onboarding_return';

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'test_monthly_lookup',
    ]);

    Cache::put('stripe_price_id:test_monthly_lookup', 'price_test_monthly', now()->addHour());
});

/**
 * A user whose checkout never reaches Stripe. Cashier's builder is mocked
 * rather than faked at the HTTP layer because what is asserted here happens on
 * our side of it — the consent and the return trip — not in the session Stripe
 * hands back. It is the shape the rest of SubscriptionTest already uses.
 */
function checkoutUser(): User
{
    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class)->shouldIgnoreMissing();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('cannotUseStripe')->andReturn(false);
    $user->shouldReceive('newSubscription')->once()->andReturn($builder);

    test()->withoutMiddleware(HandleInertiaRequests::class);

    return $user;
}

function startCheckout(User $user, ?string $source): TestResponse
{
    $query = ['plan' => 'monthly'] + ($source === null ? [] : ['source' => $source]);

    return test()->actingAs($user)->get(route('subscribe.checkout', $query));
}

it('grants the AI consent the bank gate disclosed, with the purchase', function () {
    $user = checkoutUser();
    $user->shouldReceive('recordAiConsent')->once();

    startCheckout($user, 'onboarding_bank')->assertRedirect();
});

it('grants it from the AI gate too', function () {
    $user = checkoutUser();
    $user->shouldReceive('recordAiConsent')->once();

    startCheckout($user, 'onboarding_ai')->assertRedirect();
});

/**
 * The consent travels with the plan only where the screen that sold it said
 * what leaves the account. Everywhere else the user is asked on its own terms,
 * on the screen that asks.
 */
it('grants no consent from a checkout that disclosed nothing', function () {
    $user = checkoutUser();
    $user->shouldNotReceive('recordAiConsent');

    startCheckout($user, 'connections')->assertRedirect();
});

it('grants no consent from a checkout with no upsell point at all', function () {
    $user = checkoutUser();
    $user->shouldNotReceive('recordAiConsent');

    startCheckout($user, null)->assertRedirect();
});

it('parks the bank picker to come back to, reopened', function () {
    $user = checkoutUser();

    startCheckout($user, 'onboarding_bank')
        ->assertRedirect()
        ->assertSessionHas(RETURN_KEY, ['step' => 'create-account', 'connect' => 'bank']);
});

it('parks the AI step to come back to', function () {
    $user = checkoutUser();

    startCheckout($user, 'onboarding_ai')
        ->assertRedirect()
        ->assertSessionHas(RETURN_KEY, ['step' => 'ai-suggestions']);
});

it('parks nothing for a checkout started outside the wizard', function () {
    $user = checkoutUser();

    startCheckout($user, 'connections')
        ->assertRedirect()
        ->assertSessionMissing(RETURN_KEY);
});

describe('the trip back from Stripe', function () {
    it('sends a mid-onboarding buyer back to the step that sent them', function () {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAs($user)
            ->withSession([RETURN_KEY => ['step' => 'create-account', 'connect' => 'bank']])
            ->get(route('subscribe.success'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('subscription/success')
                ->where('continueUrl', route('onboarding', ['step' => 'create-account', 'connect' => 'bank']))
            );
    });

    // The return trip is spent on arrival: a later visit is not a second
    // checkout, and must not drag the user back into a finished flow.
    it('forgets it once it has been taken', function () {
        $user = User::factory()->notOnboarded()->create();

        $this->actingAs($user)
            ->withSession([RETURN_KEY => ['step' => 'ai-suggestions']])
            ->get(route('subscribe.success'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('subscribe.success'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('continueUrl', null));
    });

    it('offers no way back into a wizard the user already finished', function () {
        $user = User::factory()->onboarded()->create();

        $this->actingAs($user)
            ->withSession([RETURN_KEY => ['step' => 'create-account', 'connect' => 'bank']])
            ->get(route('subscribe.success'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('continueUrl', null));
    });
});

describe('the free plan confirmation', function () {
    it('names the banks it is about to disconnect', function () {
        $user = User::factory()->onboarded()->create(['onboarded_at' => now()->subDay()]);
        BankingConnection::factory()->for($user)->create(['aspsp_name' => 'BBVA']);
        BankingConnection::factory()->for($user)->create(['aspsp_name' => 'BBVA']);
        BankingConnection::factory()->for($user)->create(['aspsp_name' => 'Santander']);

        $this->actingAs($user)
            ->get(route('subscribe.free-plan.confirm'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('subscription/free-plan')
                // Named once each: three connections to two banks is two names.
                ->where('banks', ['BBVA', 'Santander'])
                ->where('hasAiConsent', false)
            );
    });

    // Nothing to give up is not a decision worth asking about twice.
    it('walks a user with nothing to disconnect straight out', function () {
        $user = User::factory()->onboarded()->create(['onboarded_at' => now()->subDay()]);

        $this->actingAs($user)
            ->get(route('subscribe.free-plan.confirm'))
            ->assertRedirect(route('dashboard'));
    });

    it('is closed while the paywall is still holding the door shut', function () {
        $user = User::factory()->onboarded()->create(['onboarded_at' => now()]);
        BankingConnection::factory()->for($user)->create(['aspsp_name' => 'BBVA']);

        $this->actingAs($user)
            ->get(route('subscribe.free-plan.confirm'))
            ->assertRedirect(route('subscribe'));
    });

    it('is closed to a subscriber, who has nothing to confirm', function () {
        $user = User::factory()->onboarded()->subscribed()->create();

        $this->actingAs($user)
            ->get(route('subscribe.free-plan.confirm'))
            ->assertRedirect(route('subscribe'));
    });
});

it('hands the paywall what a former subscriber screen is built from', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => now()]);
    BankingConnection::factory()->for($user)->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_former_paynow',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_test',
        'ends_at' => now()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('canManageConnectionsForFreePlan', true)
            ->where('stats.connectionsCount', 1)
            ->has('stats.rulesCount')
            ->has('stats.endedAt')
        );
});
