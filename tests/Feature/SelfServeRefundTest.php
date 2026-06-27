<?php

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Actions\Subscription\RefundSelfServe;
use App\Features\SubscriptionExperiment;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Subscriptions\ExperimentOffer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Cashier\Payment;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Stripe\PaymentIntent;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.experiment.started_at' => '2026-06-01',
        'subscriptions.experiment.pay_now_refund_window_days' => 3,
    ]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00'));
});

function payNowSubscriber(array $overrides = []): User
{
    $user = User::factory()->onboarded()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::PAY_NOW);

    $user->subscriptions()->create(array_merge([
        'type' => 'default',
        'stripe_id' => 'sub_paynow_'.fake()->unique()->numerify('######'),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'created_at' => now(),
    ], $overrides));

    return $user;
}

it('allows a self-refund inside the window for a pay-now subscriber', function () {
    $user = payNowSubscriber();

    expect(app(ExperimentOffer::class)->canSelfRefund($user))->toBeTrue();
});

it('blocks a self-refund once the window has passed', function () {
    $user = payNowSubscriber(['created_at' => now()->subDays(5)]);

    expect(app(ExperimentOffer::class)->canSelfRefund($user))->toBeFalse();
});

it('blocks a self-refund once already refunded', function () {
    $user = payNowSubscriber(['refunded_at' => now()]);

    expect(app(ExperimentOffer::class)->canSelfRefund($user))->toBeFalse();
});

it('blocks a self-refund for non pay-now variants', function () {
    $user = payNowSubscriber();
    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::CONTROL);

    expect(app(ExperimentOffer::class)->canSelfRefund($user))->toBeFalse();
});

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

    expect(app(ExperimentOffer::class)->canSelfRefund($user))->toBeFalse();
});

it('does nothing when there is no subscription to refund', function () {
    $disconnect = Mockery::mock(DisconnectBankingConnection::class);
    $disconnect->shouldNotReceive('handle');

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('subscription')->with('default')->andReturn(null);
    $user->shouldNotReceive('refund');

    expect(fn () => (new RefundSelfServe($disconnect))->handle($user))->not->toThrow(Exception::class);
});
