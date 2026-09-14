<?php

use App\Ai\Agents\ReportSummaryAgent;
use App\Features\SubscriptionExperiment;
use App\Models\AiConsent;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Stats\ExperimentFunnelCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.experiment.started_at' => '2026-06-01',
        'subscriptions.experiment.refund_window_days' => 3,
        'subscriptions.experiment.variants' => [
            'baseline' => [],
            'short' => ['trial_days' => ['monthly' => 3, 'yearly' => 7]],
            'upfront' => ['trial_days' => ['monthly' => 0, 'yearly' => 0]],
        ],
        'subscriptions.plans.monthly.trial_days' => 15,
        'subscriptions.plans.yearly.trial_days' => 15,
        'ai_suggestions.report.excluded_emails' => [],
    ]);
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));

    // Seed the price→monthly-equivalent map so revenue is computed without Stripe.
    Cache::put('experiment_funnel_monthly_equiv', ['price_test' => 399], now()->addHour());

    ReportSummaryAgent::fake(['upfront sigue por delante, pero la muestra es demasiado pequeña.']);
});

it('skips the report and hits no external service when subscriptions are disabled', function () {
    config(['subscriptions.enabled' => false]);

    Http::fake();

    artisan('stats:experiment-funnel')
        ->expectsOutputToContain('Subscriptions are disabled; skipping the experiment funnel report.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

/**
 * Create a user whose id buckets into the wanted variant, anchored to a signup,
 * with an optional default subscription and any bank connections / AI consent
 * that mark the user as "activated" and drive the connection-cost columns.
 *
 * @param  array{status: string, at: CarbonImmutable, endsAt?: CarbonImmutable, trialEndsAt?: CarbonImmutable, refundedAt?: CarbonImmutable}|null  $subscription
 */
function experimentUser(string $variant, CarbonImmutable $signup, ?array $subscription = null, int $connections = 0, bool $aiConsent = false): User
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
            'trial_ends_at' => $subscription['trialEndsAt'] ?? null,
            'refunded_at' => $subscription['refundedAt'] ?? null,
        ]);
    }

    if ($connections > 0) {
        BankingConnection::factory()->count($connections)->for($user)->create();
    }

    if ($aiConsent) {
        AiConsent::factory()->for($user)->create();
    }

    return $user;
}

it('returns empty variants when the experiment has not started', function () {
    config(['subscriptions.experiment.started_at' => null]);

    $report = app(ExperimentFunnelCollector::class)->collect();

    expect($report['startedAt'])->toBeNull()
        ->and($report['variants']['baseline']['assigned'])->toBe(0);
});

it('attributes users and their subscription status to the right variant', function () {
    $signup = CarbonImmutable::parse('2026-06-05'); // paid-mature for every variant by the test clock

    experimentUser('baseline', $signup); // assigned, no sub
    experimentUser('baseline', $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    experimentUser('short', $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    experimentUser('upfront', $signup, ['status' => 'active', 'at' => $signup]);
    // upfront refund: active charge that was refunded -> canceled and not counted as net active.
    experimentUser('upfront', $signup, [
        'status' => 'canceled',
        'at' => $signup,
        'endsAt' => $signup->addDay(),
        'refundedAt' => $signup->addDay(),
    ]);

    $variants = app(ExperimentFunnelCollector::class)->collect()['variants'];

    expect($variants['baseline']['assigned'])->toBe(2)
        ->and($variants['baseline']['subscribed'])->toBe(1)
        ->and($variants['baseline']['active'])->toBe(1)
        ->and($variants['baseline']['netActiveRate'])->toBe(0.5)
        ->and($variants['short']['active'])->toBe(1)
        ->and($variants['upfront']['assigned'])->toBe(2)
        ->and($variants['upfront']['active'])->toBe(1)
        ->and($variants['upfront']['refunded'])->toBe(1)
        ->and($variants['upfront']['activeMature'])->toBe(1);
});

it('counts trials already scheduled to cancel separately from trials that will convert', function () {
    $signup = CarbonImmutable::parse('2026-06-28'); // still trialing under the test clock

    // A trial that will renew (no ends_at) and one the user already canceled (ends_at set).
    experimentUser('baseline', $signup, ['status' => 'trialing', 'at' => $signup]);
    experimentUser('baseline', $signup, [
        'status' => 'trialing', 'at' => $signup, 'endsAt' => $signup->addDays(15),
    ]);

    $baseline = app(ExperimentFunnelCollector::class)->collect()['variants']['baseline'];

    expect($baseline['trialing'])->toBe(2)
        ->and($baseline['trialingCanceling'])->toBe(1);
});

it('computes MRR and ARPU from the net-active subscriptions', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // One baseline assigned with no plan, one converted (€3.99/mo net-active).
    experimentUser('baseline', $signup);
    experimentUser('baseline', $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    // A refunded upfront payer contributes no revenue.
    experimentUser('upfront', $signup, [
        'status' => 'canceled', 'at' => $signup, 'endsAt' => $signup->addDay(), 'refundedAt' => $signup->addDay(),
    ]);

    $report = app(ExperimentFunnelCollector::class)->collect();
    $baseline = $report['variants']['baseline'];
    $upfront = $report['variants']['upfront'];

    expect($report['revenueAvailable'])->toBeTrue()
        ->and($report['currency'])->toBe('EUR')
        ->and($baseline['mrrCents'])->toBe(399)           // one €3.99 net-active sub
        ->and($baseline['arpuCents'])->toBe(200)          // 399 / 2 assigned, rounded
        ->and($upfront['mrrCents'])->toBe(0)              // refunded → no revenue
        ->and($upfront['arpuCents'])->toBe(0);
});

it('marks a user activated when they connect a bank or enable AI', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    experimentUser('baseline', $signup);                       // neither → not activated
    experimentUser('baseline', $signup, connections: 1);       // bank → activated
    experimentUser('baseline', $signup, aiConsent: true);      // AI → activated

    $baseline = app(ExperimentFunnelCollector::class)->collect()['variants']['baseline'];

    expect($baseline['assigned'])->toBe(3)
        ->and($baseline['activated'])->toBe(2)
        ->and($baseline['activatedMature'])->toBe(2);
});

it('computes connection cost, wasted burn and contribution margin', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // Converted control user with 2 connections: cost 2×€0.40, no burn, MRR €3.99.
    experimentUser('baseline', $signup, ['status' => 'active', 'at' => $signup->addDay()], connections: 2);
    // Activated non-payer with 3 connections: pure burn, no revenue.
    experimentUser('baseline', $signup, null, connections: 3);

    $baseline = app(ExperimentFunnelCollector::class)->collect()['variants']['baseline'];

    expect($baseline['costCents'])->toBe(200)                    // (2+3) × 40
        ->and($baseline['wastedCostCents'])->toBe(120)           // 3 × 40, the non-payer
        ->and($baseline['mrrCents'])->toBe(399)
        ->and($baseline['contributionMarginCents'])->toBe(199)   // 399 − 200
        ->and($baseline['activationToPaidRate'])->toBe(0.5);     // 1 paid ÷ 2 activated
});

it('keeps the real split even when a winner is forced, instead of collapsing onto one variant', function () {
    // force_variant pins the runtime to one variant; the report must still show
    // the real historical split, not attribute everyone to the forced winner.
    config(['subscriptions.experiment.force_variant' => 'upfront']);
    $signup = CarbonImmutable::parse('2026-06-05');

    experimentUser('baseline', $signup);
    experimentUser('short', $signup);
    experimentUser('upfront', $signup);

    $variants = app(ExperimentFunnelCollector::class)->collect()['variants'];

    expect($variants['baseline']['assigned'])->toBe(1)
        ->and($variants['short']['assigned'])->toBe(1)
        ->and($variants['upfront']['assigned'])->toBe(1);
});

it('warns instead of silently zeroing MRR when a paid sub is on an unmapped (rotated) price', function () {
    Log::spy();
    $signup = CarbonImmutable::parse('2026-06-05');

    // The seeded map only knows 'price_test'; move this paid sub onto a rotated id.
    $user = experimentUser('short', $signup, ['status' => 'active', 'at' => $signup]);
    $user->subscriptions()->first()->update(['stripe_price' => 'price_rotated_old']);

    $short = app(ExperimentFunnelCollector::class)->collect()['variants']['short'];

    expect($short['activeMature'])->toBe(1)
        ->and($short['mrrCents'])->toBe(0); // unmapped price → 0, but now loudly

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'absent from the monthly-equivalent map')
            && in_array('price_rotated_old', $context['price_ids'] ?? [], true))
        ->once();
});

it('still counts soft-deleted users so their assignment and connection cost survive', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    $user = experimentUser('baseline', $signup, null, connections: 2);
    $user->delete(); // soft delete the account

    $baseline = app(ExperimentFunnelCollector::class)->collect()['variants']['baseline'];

    expect($baseline['assigned'])->toBe(1)
        ->and($baseline['assignedMature'])->toBe(1)
        ->and($baseline['activated'])->toBe(1)     // 2 connections
        ->and($baseline['costCents'])->toBe(80)    // 2 × 40, still charged
        ->and($baseline['wastedCostCents'])->toBe(80); // never paid → pure burn
});

it('reports a matured-cohort conversion capped at 100% and prints the matured denominator', function () {
    $signup = CarbonImmutable::parse('2026-06-05'); // baseline matures by the test clock

    // Two paid, matured baseline users; only one is "activated" (connected a bank).
    // The old A2P% (Paid ÷ activated = 2 ÷ 1) would print a nonsensical 200%.
    experimentUser('baseline', $signup, ['status' => 'active', 'at' => $signup->addDay()], connections: 1);
    experimentUser('baseline', $signup, ['status' => 'active', 'at' => $signup->addDay()]);

    $baseline = app(ExperimentFunnelCollector::class)->collect()['variants']['baseline'];

    expect($baseline['assignedMature'])->toBe(2)
        ->and($baseline['activatedMature'])->toBe(1)
        ->and($baseline['activeMature'])->toBe(2)
        ->and($baseline['convertedMature'])->toBe(2)
        ->and($baseline['conversionRate'])->toBe(1.0); // Conv% = Conv ÷ MatU, always ≤ 100%

    Artisan::call('stats:experiment-funnel', ['--no-discord' => true]);
    $output = Artisan::output();

    expect($output)->toContain('UMad')  // matured denominator column is printed
        ->toContain('Conv%')
        ->toContain('100%')             // capped, not the old 200% A2P%
        ->not->toContain('200%');
});

it('reports a Wilson confidence interval and defers the verdict while samples are small', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    experimentUser('baseline', $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    experimentUser('baseline', $signup);
    experimentUser('short', $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    experimentUser('short', $signup);

    Artisan::call('stats:experiment-funnel', ['--no-discord' => true]);
    $output = Artisan::output();

    expect($output)->toContain('Significancia')
        ->toContain('Wilson')
        ->toContain('exacto de Fisher')   // verdict uses the exact test, not the z-approx
        ->toContain('no significativo')   // equal 50/50 rates, n=2 per arm → nowhere near
        ->toContain('Muestra pequeña');   // min expected conversions < 5
});

it('declares significance via the exact test when the separation is real', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // short: 5 matured converters; upfront: 5 matured non-converters (no sub).
    // Fisher exact on [[5,0],[0,5]] gives p≈0.008 < 0.0167 (Bonferroni) → significant.
    for ($i = 0; $i < 5; $i++) {
        experimentUser('short', $signup, ['status' => 'active', 'at' => $signup->addDay()]);
        experimentUser('upfront', $signup);
    }

    Artisan::call('stats:experiment-funnel', ['--no-discord' => true]);
    $output = Artisan::output();

    expect($output)->toContain('exacto de Fisher')
        ->not->toContain('no significativo'); // the exact test clears the corrected bar
});

it('measures conversion as ever-charged, not active-now, so churn does not bias it', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // 1) Still active → converted.
    experimentUser('baseline', $signup, ['status' => 'active', 'at' => $signup->addDay()]);
    // 2) Paid then churned after the trial (charged, not refunded) → converted.
    experimentUser('baseline', $signup, [
        'status' => 'canceled', 'at' => $signup, 'trialEndsAt' => $signup->addDays(15), 'endsAt' => $signup->addDays(40),
    ]);
    // 3) Canceled on/before the trial end (never charged) → NOT converted.
    experimentUser('baseline', $signup, [
        'status' => 'canceled', 'at' => $signup, 'trialEndsAt' => $signup->addDays(15), 'endsAt' => $signup->addDays(10),
    ]);
    // 4) Refunded → NOT converted.
    experimentUser('baseline', $signup, [
        'status' => 'canceled', 'at' => $signup, 'endsAt' => $signup->addDay(), 'refundedAt' => $signup->addDay(),
    ]);

    $baseline = app(ExperimentFunnelCollector::class)->collect()['variants']['baseline'];

    expect($baseline['assignedMature'])->toBe(4)
        ->and($baseline['convertedMature'])->toBe(2)   // #1 active + #2 churned-after-paying
        ->and($baseline['activeMature'])->toBe(1)      // only #1 is active now
        ->and($baseline['conversionRate'])->toBe(0.5); // 2 ÷ 4, time-invariant
});

it('excludes churned payers from burn but keeps refunds as burn', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    // Paid then canceled (not refunded): converted, so its cost is NOT burn.
    experimentUser('baseline', $signup, [
        'status' => 'canceled', 'at' => $signup, 'endsAt' => $signup->addDays(20),
    ], connections: 2);
    // Paid then refunded: zero net revenue, so its cost IS burn.
    experimentUser('baseline', $signup, [
        'status' => 'canceled', 'at' => $signup, 'endsAt' => $signup->addDay(), 'refundedAt' => $signup->addDay(),
    ], connections: 3);

    $baseline = app(ExperimentFunnelCollector::class)->collect()['variants']['baseline'];

    expect($baseline['costCents'])->toBe(200)          // (2 + 3) × 40, all mature connections
        ->and($baseline['wastedCostCents'])->toBe(120) // only the refunded user's 3 × 40
        ->and($baseline['refunded'])->toBe(1);
});

it('scales connection cost by the cost-per-connection argument', function () {
    $signup = CarbonImmutable::parse('2026-06-05');

    experimentUser('baseline', $signup, null, connections: 2);

    $baseline = app(ExperimentFunnelCollector::class)->collect(100)['variants']['baseline'];

    expect($baseline['costCents'])->toBe(200)             // 2 × 100
        ->and($baseline['wastedCostCents'])->toBe(200);
});

it('marks revenue unavailable when Stripe prices cannot be loaded', function () {
    Cache::forget('experiment_funnel_monthly_equiv');
    Cache::put('experiment_funnel_monthly_equiv', [], now()->addHour()); // empty = unavailable

    experimentUser('baseline', CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active', 'at' => CarbonImmutable::parse('2026-06-05'),
    ]);

    $report = app(ExperimentFunnelCollector::class)->collect();

    expect($report['revenueAvailable'])->toBeFalse()
        ->and($report['variants']['baseline']['mrrCents'])->toBe(0);
});

it('leaves young cohorts out of the mature net-active rate', function () {
    // upfront decides in 3d (+3 buffer); a 2-day-old signup is not mature yet.
    experimentUser('upfront', CarbonImmutable::now()->subDays(2), [
        'status' => 'active',
        'at' => CarbonImmutable::now()->subDays(2),
    ]);

    $upfront = app(ExperimentFunnelCollector::class)->collect()['variants']['upfront'];

    expect($upfront['assigned'])->toBe(1)
        ->and($upfront['active'])->toBe(1)
        ->and($upfront['assignedMature'])->toBe(0)
        ->and($upfront['netActiveRate'])->toBeNull();
});

it('posts the experiment funnel embed to discord', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    experimentUser('baseline', CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active',
        'at' => CarbonImmutable::parse('2026-06-05'),
    ]);

    artisan('stats:experiment-funnel')->assertSuccessful();

    Http::assertSent(function ($request) {
        $embed = $request['embeds'][0];

        return $request->url() === 'https://discord.test/hook'
            && str_contains($embed['title'], 'Experimento')
            // Spanish table header and legend, no leftover English.
            && str_contains($embed['description'], 'Variante')
            && collect($embed['fields'])->contains(fn ($field) => $field['name'] === 'Leyenda');
    });
});

it('opens the embed with the AI summary, above the table', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);
    ReportSummaryAgent::fake(['baseline convierte mejor, pero la diferencia no es significativa.']);

    experimentUser('baseline', CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active',
        'at' => CarbonImmutable::parse('2026-06-05'),
    ]);

    artisan('stats:experiment-funnel')->assertSuccessful();

    Http::assertSent(fn ($request) => str_starts_with(
        $request['embeds'][0]['description'],
        "baseline convierte mejor, pero la diferencia no es significativa.\n\n```",
    ));
});

it('feeds the summary the variant figures and the significance verdict', function () {
    Http::fake();

    experimentUser('baseline', CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active',
        'at' => CarbonImmutable::parse('2026-06-05'),
    ]);

    artisan('stats:experiment-funnel', ['--no-discord' => true])->assertSuccessful();

    ReportSummaryAgent::assertPrompted(function ($prompt): bool {
        $payload = json_decode($prompt->prompt, true)['current'];

        return $payload['variants']['baseline']['converted_mature'] === 1
            && $payload['currency'] === 'EUR'
            && collect($payload['significance'])->contains(fn (string $line) => str_contains($line, 'Wilson'));
    });
});

it('still posts the report when the AI summary fails', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);
    ReportSummaryAgent::fake(fn () => throw new RuntimeException('provider down'));

    experimentUser('baseline', CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active',
        'at' => CarbonImmutable::parse('2026-06-05'),
    ]);

    artisan('stats:experiment-funnel')->assertSuccessful();

    Http::assertSent(fn ($request) => str_starts_with($request['embeds'][0]['description'], '```')
        && str_contains($request['embeds'][0]['description'], 'Variante'));
});

it('matures each variant on its own longest trial, or the refund window when it charges upfront', function () {
    // 12 days ago: past 'short' (7d trial) + 3d settle, short of 'baseline'
    // (15d plan trial) + 3d, and well past 'upfront' (3d refund window) + 3d.
    $signup = CarbonImmutable::now()->subDays(12);

    experimentUser('baseline', $signup);
    experimentUser('short', $signup);
    experimentUser('upfront', $signup);

    $variants = app(ExperimentFunnelCollector::class)->collect()['variants'];

    expect($variants['baseline']['assignedMature'])->toBe(0)
        ->and($variants['short']['assignedMature'])->toBe(1)
        ->and($variants['upfront']['assignedMature'])->toBe(1);
});

it('does not post when no variant is declared', function () {
    config(['subscriptions.experiment.variants' => []]);
    Http::fake();

    artisan('stats:experiment-funnel')->assertSuccessful();

    ReportSummaryAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

it('does not post when the experiment has not started', function () {
    config(['subscriptions.experiment.started_at' => null]);
    Http::fake();

    artisan('stats:experiment-funnel')->assertSuccessful();

    ReportSummaryAgent::assertNeverPrompted();
    Http::assertNothingSent();
});

it('prints the report without posting to discord when --no-discord is set', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    experimentUser('baseline', CarbonImmutable::parse('2026-06-05'), [
        'status' => 'active',
        'at' => CarbonImmutable::parse('2026-06-05'),
    ]);

    artisan('stats:experiment-funnel', ['--no-discord' => true])
        ->expectsOutputToContain('baseline')
        ->assertSuccessful();

    Http::assertNothingSent();
});
