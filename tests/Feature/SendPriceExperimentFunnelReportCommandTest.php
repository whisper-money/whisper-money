<?php

use App\Features\PriceExperiment;
use App\Models\AiConsent;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Stats\PriceExperimentFunnelCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.price_experiment.started_at' => '2026-06-01',
        'subscriptions.price_experiment.force_variant' => null,
        'subscriptions.plans.monthly.trial_days' => 15,
        'ai_suggestions.report.excluded_emails' => [],
    ]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

    // Seed the price→monthly-equivalent map so revenue is computed without Stripe:
    // control €3.99, high €8.99.
    Cache::put('experiment_funnel_monthly_equiv', ['price_control' => 399, 'price_high' => 899], now()->addHour());
});

/**
 * Create a user whose id buckets into the wanted price variant, with an optional
 * default subscription, live (active) bank connections that drive the monthly cost,
 * extra soft-deleted connections that count as "ever activated" but cost nothing,
 * and optional AI consent.
 *
 * @param  array{status: string, at: CarbonImmutable, price?: string, endsAt?: CarbonImmutable, trialEndsAt?: CarbonImmutable, refundedAt?: CarbonImmutable}|null  $subscription
 */
function priceUser(string $variant, CarbonImmutable $signup, ?array $subscription = null, int $connections = 0, int $deletedConnections = 0, bool $aiConsent = false): User
{
    do {
        $id = (string) Str::uuid();
    } while (PriceExperiment::bucket($id) !== $variant);

    $user = User::factory()->create(['id' => $id, 'created_at' => $signup]);

    if ($subscription !== null) {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(12),
            'stripe_status' => $subscription['status'],
            'stripe_price' => $subscription['price'] ?? 'price_control',
            'created_at' => $subscription['at'],
            'ends_at' => $subscription['endsAt'] ?? null,
            'trial_ends_at' => $subscription['trialEndsAt'] ?? null,
            'refunded_at' => $subscription['refundedAt'] ?? null,
        ]);
    }

    if ($connections > 0) {
        BankingConnection::factory()->count($connections)->for($user)->create();
    }

    if ($deletedConnections > 0) {
        BankingConnection::factory()->count($deletedConnections)->for($user)->create()
            ->each(fn (BankingConnection $connection) => $connection->delete());
    }

    if ($aiConsent) {
        AiConsent::factory()->for($user)->create();
    }

    return $user;
}

it('returns empty variants when the experiment has not started', function () {
    config(['subscriptions.price_experiment.started_at' => null]);

    $report = app(PriceExperimentFunnelCollector::class)->collect();

    expect($report['startedAt'])->toBeNull()
        ->and($report['variants'][PriceExperiment::CONTROL]['assigned'])->toBe(0)
        ->and($report['variants'][PriceExperiment::HIGH]['assigned'])->toBe(0);
});

it('attributes users to the right price variant and counts the funnel', function () {
    $signup = CarbonImmutable::parse('2026-06-05'); // mature (15d + 3d) by the test clock

    priceUser(PriceExperiment::CONTROL, $signup);
    priceUser(PriceExperiment::CONTROL, $signup, ['status' => 'active', 'at' => $signup->addDay(), 'price' => 'price_control']);
    priceUser(PriceExperiment::HIGH, $signup, ['status' => 'active', 'at' => $signup->addDay(), 'price' => 'price_high']);

    $variants = app(PriceExperimentFunnelCollector::class)->collect()['variants'];

    expect($variants[PriceExperiment::CONTROL]['assigned'])->toBe(2)
        ->and($variants[PriceExperiment::CONTROL]['subscribed'])->toBe(1)
        ->and($variants[PriceExperiment::CONTROL]['activeMature'])->toBe(1)
        ->and($variants[PriceExperiment::CONTROL]['convertedMature'])->toBe(1)
        ->and($variants[PriceExperiment::CONTROL]['conversionRate'])->toBe(0.5)
        ->and($variants[PriceExperiment::HIGH]['assigned'])->toBe(1)
        ->and($variants[PriceExperiment::HIGH]['activeMature'])->toBe(1);
});

it('books MRR at each variant price and reports contribution margin per assigned user', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // High arm: one payer at €8.99 with 1 active connection (cost €0.40).
    priceUser(PriceExperiment::HIGH, $signup, ['status' => 'active', 'at' => $signup, 'price' => 'price_high'], connections: 1);
    // High arm: one assigned non-payer, no connections.
    priceUser(PriceExperiment::HIGH, $signup);

    $high = app(PriceExperimentFunnelCollector::class)->collect()['variants'][PriceExperiment::HIGH];

    expect($high['mrrCents'])->toBe(899)                    // €8.99 at the high price
        ->and($high['costCents'])->toBe(40)                 // 1 active connection
        ->and($high['contributionMarginCents'])->toBe(859)  // 899 − 40
        ->and($high['assignedMature'])->toBe(2)
        ->and($high['cmPerAssignedCents'])->toBe(430)       // round(859 / 2)
        ->and($high['connectionsPerPayer'])->toBe(1.0);
});

it('counts revoked connections as activation but charges cost only for live ones', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // 1 live connection + 2 soft-deleted: activated (ever connected), but the
    // monthly cost is only the 1 live connection — the stock-vs-flow fix.
    priceUser(PriceExperiment::CONTROL, $signup, connections: 1, deletedConnections: 2);

    $control = app(PriceExperimentFunnelCollector::class)->collect()['variants'][PriceExperiment::CONTROL];

    expect($control['activated'])->toBe(1)
        ->and($control['activatedMature'])->toBe(1)
        ->and($control['costCents'])->toBe(40); // 1 live × 40, not 3 × 40
});

it('leaves young cohorts out of the matured metrics', function () {
    priceUser(PriceExperiment::HIGH, CarbonImmutable::now()->subDays(5), [
        'status' => 'active', 'at' => CarbonImmutable::now()->subDays(5), 'price' => 'price_high',
    ]);

    $high = app(PriceExperimentFunnelCollector::class)->collect()['variants'][PriceExperiment::HIGH];

    expect($high['assigned'])->toBe(1)
        ->and($high['assignedMature'])->toBe(0)         // 5d < 18d window
        ->and($high['conversionRate'])->toBeNull();
});

it('keeps the split even when a winner is forced, instead of collapsing onto one variant', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::HIGH]);
    $signup = CarbonImmutable::parse('2026-06-05');

    priceUser(PriceExperiment::CONTROL, $signup);
    priceUser(PriceExperiment::HIGH, $signup);

    $variants = app(PriceExperimentFunnelCollector::class)->collect()['variants'];

    expect($variants[PriceExperiment::CONTROL]['assigned'])->toBe(1)
        ->and($variants[PriceExperiment::HIGH]['assigned'])->toBe(1);
});

it('prints the primary CM/user (Welch) and conversion guardrail blocks but withholds the verdict at small n', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    for ($i = 0; $i < 3; $i++) {
        priceUser(PriceExperiment::CONTROL, $signup, ['status' => 'active', 'at' => $signup, 'price' => 'price_control']);
        priceUser(PriceExperiment::CONTROL, $signup);
        priceUser(PriceExperiment::HIGH, $signup, ['status' => 'active', 'at' => $signup, 'price' => 'price_high']);
        priceUser(PriceExperiment::HIGH, $signup);
    }

    Artisan::call('stats:price-experiment-funnel', ['--no-discord' => true]);
    $output = Artisan::output();

    expect($output)->toContain('PRIMARY')
        ->and($output)->toContain('GUARDRAIL')
        ->and($output)->toContain('Fisher exact')
        ->and($output)->toContain('verdict withheld') // 6 per arm < MIN_WELCH_N
        ->and($output)->not->toContain('α=0.05 ->'); // no significance verdict at small n
});

it('emits a Welch significance verdict once both arms clear the small-sample floor', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // 30 per arm (== MIN_WELCH_N), half net-active payers, so there is real variance.
    for ($i = 0; $i < 30; $i++) {
        $sub = $i % 2 === 0 ? ['status' => 'active', 'at' => $signup] : null;
        priceUser(PriceExperiment::CONTROL, $signup, $sub === null ? null : [...$sub, 'price' => 'price_control']);
        priceUser(PriceExperiment::HIGH, $signup, $sub === null ? null : [...$sub, 'price' => 'price_high']);
    }

    Artisan::call('stats:price-experiment-funnel', ['--no-discord' => true]);
    $output = Artisan::output();

    expect($output)->toContain('α=0.05 ->')
        ->and($output)->not->toContain('verdict withheld');
});

it('surfaces an unmapped-price warning in the report, not just the logs', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // Net-active sub on a price id the seeded map does not know → MRR silently 0.
    priceUser(PriceExperiment::HIGH, $signup, ['status' => 'active', 'at' => $signup, 'price' => 'price_rotated_old']);

    Artisan::call('stats:price-experiment-funnel', ['--no-discord' => true]);
    $output = Artisan::output();

    expect($output)->toContain('UNMAPPED PRICES')
        ->and($output)->toContain('price_rotated_old');
});

it('flags a sample-ratio mismatch when the assignment split is lopsided', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    for ($i = 0; $i < 10; $i++) {
        priceUser(PriceExperiment::CONTROL, $signup);
    }
    priceUser(PriceExperiment::HIGH, $signup);

    artisan('stats:price-experiment-funnel', ['--no-discord' => true])
        ->expectsOutputToContain('SRM')
        ->expectsOutputToContain('IMBALANCE')
        ->assertSuccessful();
});

it('does not flag SRM when the split is balanced', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    for ($i = 0; $i < 4; $i++) {
        priceUser(PriceExperiment::CONTROL, $signup);
        priceUser(PriceExperiment::HIGH, $signup);
    }

    artisan('stats:price-experiment-funnel', ['--no-discord' => true])
        ->expectsOutputToContain('SRM')
        ->doesntExpectOutputToContain('IMBALANCE')
        ->assertSuccessful();
});

it('posts the price experiment embed to discord with the monitoring-only warning', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    priceUser(PriceExperiment::CONTROL, CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active', 'at' => CarbonImmutable::parse('2026-06-05'), 'price' => 'price_control',
    ]);

    artisan('stats:price-experiment-funnel')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/hook'
        && str_contains($request['embeds'][0]['title'], 'Price Experiment')
        && str_contains(json_encode($request['embeds'][0]), 'MONITORING ONLY'));
});

it('does not post when the experiment has not started', function () {
    config(['subscriptions.price_experiment.started_at' => null]);
    Http::fake();

    artisan('stats:price-experiment-funnel')->assertSuccessful();

    Http::assertNothingSent();
});
