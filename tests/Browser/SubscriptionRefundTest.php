<?php

use App\Actions\Subscription\RefundSelfServe;
use App\Features\SubscriptionExperiment;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * Browser coverage of the pay_now self-service refund — the most delicate path,
 * since it moves money and disconnects accounts. The RefundSelfServe action is
 * swapped for a double so the test never hits Stripe; the double applies the same
 * DB effect (stamping refunded_at) so the refund control genuinely disappears
 * after confirming, proving the full click -> POST -> re-render loop. The real
 * Stripe refund/cancel shapes are covered by tests/Feature/SelfServeRefundTest
 * and must still be smoke-checked against the Stripe sandbox before launch.
 */
beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.experiment.started_at' => '2026-06-01',
        'subscriptions.experiment.pay_now_refund_window_days' => 3,
    ]);
});

function browserPayNowUser(array $subscriptionOverrides = []): User
{
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::PAY_NOW);

    $user->subscriptions()->create(array_merge([
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(12),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'created_at' => now(),
    ], $subscriptionOverrides));

    return $user;
}

it('lets a pay-now subscriber self-refund from billing settings', function () {
    $user = browserPayNowUser();

    $action = Mockery::mock(RefundSelfServe::class);
    $action->shouldReceive('handle')->once()->andReturnUsing(function () use ($user): void {
        $user->subscription('default')->forceFill(['refunded_at' => now()])->save();
    });
    app()->instance(RefundSelfServe::class, $action);

    actingAs($user);

    visit('/settings/billing')
        ->assertSee('Money-back guarantee')
        ->assertSee('Request a refund')
        ->screenshot(filename: 'refund-card-visible')
        ->click('Request a refund')
        ->waitForText('Confirm refund', 5)
        ->assertSee('Keep my plan')
        ->screenshot(filename: 'refund-confirm-step')
        ->click('Confirm refund')
        ->wait(3)
        ->assertDontSee('Money-back guarantee')
        ->screenshot(filename: 'refund-completed')
        ->assertNoJavascriptErrors();
});

it('lets the user back out of the refund without charging anything', function () {
    $user = browserPayNowUser();

    $action = Mockery::mock(RefundSelfServe::class);
    $action->shouldNotReceive('handle');
    app()->instance(RefundSelfServe::class, $action);

    actingAs($user);

    visit('/settings/billing')
        ->assertSee('Request a refund')
        ->click('Request a refund')
        ->waitForText('Keep my plan', 5)
        ->click('Keep my plan')
        ->wait(1)
        ->assertSee('Request a refund')
        ->assertDontSee('Confirm refund')
        ->assertNoJavascriptErrors();
});

it('hides the refund control once the window has passed', function () {
    $user = browserPayNowUser(['created_at' => now()->subDays(5)]);

    actingAs($user);

    visit('/settings/billing')
        ->assertSee('Pro Plan Active')
        ->assertDontSee('Money-back guarantee')
        ->assertNoJavascriptErrors();
});

it('does not offer a refund to control-group subscribers', function () {
    $user = browserPayNowUser();
    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::CONTROL);

    actingAs($user);

    visit('/settings/billing')
        ->assertSee('Pro Plan Active')
        ->assertDontSee('Money-back guarantee')
        ->assertNoJavascriptErrors();
});
