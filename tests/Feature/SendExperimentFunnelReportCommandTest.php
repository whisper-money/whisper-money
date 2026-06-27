<?php

use App\Features\SubscriptionExperiment;
use App\Models\User;
use App\Services\Stats\ExperimentFunnelCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.experiment.started_at' => '2026-06-01',
        'subscriptions.experiment.reduced_trial.monthly' => 3,
        'subscriptions.experiment.reduced_trial.yearly' => 7,
        'subscriptions.experiment.pay_now_refund_window_days' => 3,
        'subscriptions.plans.monthly.trial_days' => 15,
        'ai_suggestions.report.excluded_emails' => [],
    ]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

    // Seed the price→monthly-equivalent map so revenue is computed without Stripe.
    Cache::put('experiment_funnel_monthly_equiv', ['price_test' => 399], now()->addHour());
});

/**
 * Create a user whose id buckets into the wanted variant, anchored to a signup,
 * with an optional default subscription.
 *
 * @param  array{status: string, at: CarbonImmutable, endsAt?: CarbonImmutable, refundedAt?: CarbonImmutable}|null  $subscription
 */
function experimentUser(string $variant, CarbonImmutable $signup, ?array $subscription = null): User
{
    do {
        $id = (string) Str::uuid();
    } while (SubscriptionExperiment::bucket($id) !== $variant);

    $user = User::factory()->create(['id' => $id, 'created_at' => $signup]);

    if ($subscription !== null) {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(12),
            'stripe_status' => $subscription['status'],
            'stripe_price' => 'price_test',
            'created_at' => $subscription['at'],
            'ends_at' => $subscription['endsAt'] ?? null,
            'refunded_at' => $subscription['refundedAt'] ?? null,
        ]);
    }

    return $user;
}

it('returns empty variants when the experiment has not started', function () {
    config(['subscriptions.experiment.started_at' => null]);

    $report = app(ExperimentFunnelCollector::class)->collect();

    expect($report['startedAt'])->toBeNull()
        ->and($report['variants'][SubscriptionExperiment::CONTROL]['assigned'])->toBe(0);
});

it('attributes users and their subscription status to the right variant', function () {
    $signup = CarbonImmutable::parse('2026-06-05'); // paid-mature for every variant by the test clock

    experimentUser(SubscriptionExperiment::CONTROL, $signup); // assigned, no sub
    experimentUser(SubscriptionExperiment::CONTROL, $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    experimentUser(SubscriptionExperiment::REDUCED_TRIAL, $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    experimentUser(SubscriptionExperiment::PAY_NOW, $signup, ['status' => 'active', 'at' => $signup]);
    // pay_now refund: active charge that was refunded -> canceled and not counted as net active.
    experimentUser(SubscriptionExperiment::PAY_NOW, $signup, [
        'status' => 'canceled',
        'at' => $signup,
        'endsAt' => $signup->addDay(),
        'refundedAt' => $signup->addDay(),
    ]);

    $variants = app(ExperimentFunnelCollector::class)->collect()['variants'];

    expect($variants[SubscriptionExperiment::CONTROL]['assigned'])->toBe(2)
        ->and($variants[SubscriptionExperiment::CONTROL]['subscribed'])->toBe(1)
        ->and($variants[SubscriptionExperiment::CONTROL]['active'])->toBe(1)
        ->and($variants[SubscriptionExperiment::CONTROL]['netActiveRate'])->toBe(0.5)
        ->and($variants[SubscriptionExperiment::REDUCED_TRIAL]['active'])->toBe(1)
        ->and($variants[SubscriptionExperiment::PAY_NOW]['assigned'])->toBe(2)
        ->and($variants[SubscriptionExperiment::PAY_NOW]['active'])->toBe(1)
        ->and($variants[SubscriptionExperiment::PAY_NOW]['refunded'])->toBe(1)
        ->and($variants[SubscriptionExperiment::PAY_NOW]['activeMature'])->toBe(1);
});

it('computes MRR and ARPU from the net-active subscriptions', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // One control assigned with no plan, one converted (€3.99/mo net-active).
    experimentUser(SubscriptionExperiment::CONTROL, $signup);
    experimentUser(SubscriptionExperiment::CONTROL, $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    // A refunded pay_now contributes no revenue.
    experimentUser(SubscriptionExperiment::PAY_NOW, $signup, [
        'status' => 'canceled', 'at' => $signup, 'endsAt' => $signup->addDay(), 'refundedAt' => $signup->addDay(),
    ]);

    $report = app(ExperimentFunnelCollector::class)->collect();
    $control = $report['variants'][SubscriptionExperiment::CONTROL];
    $payNow = $report['variants'][SubscriptionExperiment::PAY_NOW];

    expect($report['revenueAvailable'])->toBeTrue()
        ->and($report['currency'])->toBe('EUR')
        ->and($control['mrrCents'])->toBe(399)           // one €3.99 net-active sub
        ->and($control['arpuCents'])->toBe(200)          // 399 / 2 assigned, rounded
        ->and($payNow['mrrCents'])->toBe(0)              // refunded → no revenue
        ->and($payNow['arpuCents'])->toBe(0);
});

it('marks revenue unavailable when Stripe prices cannot be loaded', function () {
    Cache::forget('experiment_funnel_monthly_equiv');
    Cache::put('experiment_funnel_monthly_equiv', [], now()->addHour()); // empty = unavailable

    experimentUser(SubscriptionExperiment::CONTROL, CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active', 'at' => CarbonImmutable::parse('2026-06-05'),
    ]);

    $report = app(ExperimentFunnelCollector::class)->collect();

    expect($report['revenueAvailable'])->toBeFalse()
        ->and($report['variants'][SubscriptionExperiment::CONTROL]['mrrCents'])->toBe(0);
});

it('leaves young cohorts out of the mature net-active rate', function () {
    // pay_now decides in 3d (+3 buffer); a 2-day-old signup is not mature yet.
    experimentUser(SubscriptionExperiment::PAY_NOW, CarbonImmutable::now()->subDays(2), [
        'status' => 'active',
        'at' => CarbonImmutable::now()->subDays(2),
    ]);

    $payNow = app(ExperimentFunnelCollector::class)->collect()['variants'][SubscriptionExperiment::PAY_NOW];

    expect($payNow['assigned'])->toBe(1)
        ->and($payNow['active'])->toBe(1)
        ->and($payNow['assignedMature'])->toBe(0)
        ->and($payNow['netActiveRate'])->toBeNull();
});

it('posts the experiment funnel embed to discord', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    experimentUser(SubscriptionExperiment::CONTROL, CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active',
        'at' => CarbonImmutable::parse('2026-06-05'),
    ]);

    artisan('stats:experiment-funnel')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/hook'
        && str_contains($request['embeds'][0]['title'], 'Experiment'));
});

it('does not post when the experiment has not started', function () {
    config(['subscriptions.experiment.started_at' => null]);
    Http::fake();

    artisan('stats:experiment-funnel')->assertSuccessful();

    Http::assertNothingSent();
});
