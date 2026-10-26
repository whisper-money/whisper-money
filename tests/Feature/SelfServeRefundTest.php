<?php

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Actions\Subscription\RefundSelfServe;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Subscriptions\RefundWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Cashier\Payment;
use Laravel\Cashier\Subscription;
use Stripe\PaymentIntent;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.refund_window_days' => 3,
    ]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));
});

/**
 * Someone charged in full at signup: a subscription with no trial on it. That,
 * and not what the plans are configured to sell today, is what the refund gate
 * reads.
 */
function payNowSubscriber(array $overrides = []): User
{
    $user = User::factory()->onboarded()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    $user->subscriptions()->create(array_merge([
        'type' => 'default',
        'stripe_id' => 'sub_paynow_'.fake()->unique()->numerify('######'),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'trial_ends_at' => null,
        'created_at' => now(),
    ], $overrides));

    return $user;
}

it('opens the self-refund window only for a pay-now subscriber inside it', function (array $overrides, array $config, bool $isOpen) {
    config($config);

    $user = payNowSubscriber($overrides);

    expect(app(RefundWindow::class)->isOpenFor($user))->toBe($isOpen);
})->with([
    // Rows that read the clock are closures so they resolve after beforeEach freezes it.
    'inside the window' => [[], [], true],
    'window passed' => [fn () => ['created_at' => now()->subDays(5)], [], false],
    'already refunded' => [fn () => ['refunded_at' => now()], [], false],
    'seeded demo subscription' => [fn () => ['stripe_id' => 'sub_demo_'.fake()->uuid()], [], false],
    'subscriber who got a trial' => [fn () => ['trial_ends_at' => now()->addDays(7)], [], false],
    // A trial that has already run out still means the user was never charged at
    // signup, so there is nothing to give back — the date being in the past must
    // not read as "no trial".
    'subscriber whose trial already ended' => [fn () => ['trial_ends_at' => now()->subDay()], [], false],
    // The case the experiment left behind in production: users charged in full
    // while `SUBSCRIPTION_PAY_NOW` was on for their arm. Putting the trial back on
    // the plans must not retract the window they are still inside — their money is
    // already gone, and the button is the only way back.
    'upfront payer while the plans carry a trial' => [
        [],
        ['subscriptions.plans.monthly.trial_days' => 7, 'subscriptions.plans.yearly.trial_days' => 14],
        true,
    ],
]);

it('runs the refund action when eligible and reports it on the billing screen', function () {
    $user = payNowSubscriber();

    $action = Mockery::mock(RefundSelfServe::class);
    $action->shouldReceive('handle')->once();
    app()->instance(RefundSelfServe::class, $action);

    $this->actingAs($user)
        ->post(route('settings.billing.refund'))
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHas('status');
});

it('rejects the refund request when not eligible', function () {
    $user = payNowSubscriber(['created_at' => now()->subDays(5)]);

    $action = Mockery::mock(RefundSelfServe::class);
    $action->shouldNotReceive('handle');
    app()->instance(RefundSelfServe::class, $action);

    $this->actingAs($user)
        ->post(route('settings.billing.refund'))
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHasErrors(['refund']);
});

it('announces a successful refund to discord', function () {
    config(['services.discord.webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    $user = payNowSubscriber();
    $action = Mockery::mock(RefundSelfServe::class);
    $action->shouldReceive('handle')->once();
    app()->instance(RefundSelfServe::class, $action);

    $this->actingAs($user)
        ->post(route('settings.billing.refund'))
        ->assertRedirect(route('settings.billing'));

    Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/hook'
        && str_contains(strtolower($request['embeds'][0]['title']), 'refund processed'));
});

it('announces a failed refund to discord and surfaces the error', function () {
    config(['services.discord.webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    $user = payNowSubscriber();
    $action = Mockery::mock(RefundSelfServe::class);
    $action->shouldReceive('handle')->once()->andThrow(new RuntimeException('stripe down'));
    app()->instance(RefundSelfServe::class, $action);

    $this->actingAs($user)
        ->post(route('settings.billing.refund'))
        ->assertStatus(500);

    Http::assertSent(fn ($request) => isset($request['embeds'][0]['title'])
        && str_contains(strtolower($request['embeds'][0]['title']), 'failed'));
});

it('refunds the charge, cancels the subscription and disconnects connections', function () {
    $payment = new Payment(new PaymentIntent('pi_test_123'));

    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('getAttribute')->with('refunded_at')->andReturn(null);
    $subscription->shouldReceive('latestPayment')->once()->andReturn($payment);
    $subscription->shouldReceive('cancelNow')->once();
    $subscription->shouldReceive('forceFill')->once()
        ->with(Mockery::on(fn ($attrs) => array_key_exists('refunded_at', $attrs)))
        ->andReturnSelf();
    $subscription->shouldReceive('save')->once();

    $relation = Mockery::mock(HasMany::class);
    $relation->shouldReceive('get')->once()->andReturn(collect([
        Mockery::mock(BankingConnection::class),
        Mockery::mock(BankingConnection::class),
    ]));

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('subscription')->with('default')->andReturn($subscription);
    $user->shouldReceive('refund')->once()->with('pi_test_123');
    $user->shouldReceive('bankingConnections')->andReturn($relation);

    $disconnect = Mockery::mock(DisconnectBankingConnection::class);
    $disconnect->shouldReceive('handle')->twice();

    (new RefundSelfServe($disconnect))->handle($user);
});

/**
 * The page gate is a cheap predicate over our own columns — a null trial inside
 * the window — so it can let through someone who was never actually charged.
 * Stripe is the authority on that, and it is asked here. Carrying on would take
 * the plan and the bank connections away and hand back nothing, silently.
 */
it('refuses to refund, cancel or disconnect when Stripe has no payment', function () {
    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('getAttribute')->with('refunded_at')->andReturn(null);
    $subscription->shouldReceive('latestPayment')->once()->andReturn(null);
    $subscription->shouldNotReceive('forceFill');
    $subscription->shouldNotReceive('save');
    $subscription->shouldNotReceive('cancelNow');

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('subscription')->with('default')->andReturn($subscription);
    $user->shouldNotReceive('refund');

    $disconnect = Mockery::mock(DisconnectBankingConnection::class);
    $disconnect->shouldNotReceive('handle');

    expect(fn () => (new RefundSelfServe($disconnect))->handle($user))
        ->toThrow(RuntimeException::class);
});

it('records the refund before cleanup so a cleanup failure cannot double-refund', function () {
    $payment = new Payment(new PaymentIntent('pi_test_123'));

    $subscription = Mockery::mock(Subscription::class);
    $subscription->shouldReceive('getAttribute')->with('refunded_at')->andReturn(null);
    $subscription->shouldReceive('latestPayment')->andReturn($payment);
    $subscription->shouldReceive('forceFill')
        ->with(Mockery::on(fn ($attrs) => array_key_exists('refunded_at', $attrs)))
        ->once()->andReturnSelf();
    $subscription->shouldReceive('save')->once();
    $subscription->shouldReceive('cancelNow')->once()->andThrow(new RuntimeException('stripe down'));

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('subscription')->with('default')->andReturn($subscription);
    $user->shouldReceive('refund')->once()->with('pi_test_123');

    $disconnect = Mockery::mock(DisconnectBankingConnection::class);
    $disconnect->shouldNotReceive('handle');

    // refunded_at is saved before cancelNow throws, and the failure is swallowed.
    expect(fn () => (new RefundSelfServe($disconnect))->handle($user))->not->toThrow(RuntimeException::class);
});

it('skips a subscription that was already refunded', function () {
    $user = payNowSubscriber(['refunded_at' => now()]);

    $disconnect = Mockery::mock(DisconnectBankingConnection::class);
    $disconnect->shouldNotReceive('handle');

    expect(fn () => (new RefundSelfServe($disconnect))->handle($user))->not->toThrow(Exception::class);

    expect(app(RefundWindow::class)->isOpenFor($user))->toBeFalse();
});

it('does nothing when there is no subscription to refund', function () {
    $disconnect = Mockery::mock(DisconnectBankingConnection::class);
    $disconnect->shouldNotReceive('handle');

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('subscription')->with('default')->andReturn(null);
    $user->shouldNotReceive('refund');

    expect(fn () => (new RefundSelfServe($disconnect))->handle($user))->not->toThrow(Exception::class);
});
