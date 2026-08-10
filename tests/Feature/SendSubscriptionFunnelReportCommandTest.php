<?php

use App\Ai\Agents\ReportSummaryAgent;
use App\Models\User;
use App\Services\Stats\SubscriptionFunnelCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

function funnelNow(): CarbonImmutable
{
    return CarbonImmutable::create(2026, 6, 17, 12, 0, 0, 'UTC');
}

/**
 * Create a user anchored to a signup, optionally with a default subscription.
 *
 * @param  array{status: string, at: CarbonImmutable, endsAt?: CarbonImmutable}|null  $subscription
 */
function funnelUser(CarbonImmutable $signup, ?array $subscription = null): User
{
    $user = User::factory()->create([
        'email' => fake()->unique()->safeEmail(),
        'created_at' => $signup,
    ]);

    if ($subscription !== null) {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(12),
            'stripe_status' => $subscription['status'],
            'stripe_price' => 'price_test',
            'created_at' => $subscription['at'],
            'ends_at' => $subscription['endsAt'] ?? null,
        ]);
    }

    return $user;
}

/**
 * @param  array{weeks: list<array<string, mixed>>}  $report
 * @return array<string, mixed>
 */
function funnelRow(array $report, CarbonImmutable $signup): array
{
    $label = $signup->startOfWeek(CarbonImmutable::MONDAY)->format('o-\WW');

    foreach ($report['weeks'] as $row) {
        if ($row['week'] === $label) {
            return $row;
        }
    }

    throw new RuntimeException("No cohort row found for week {$label}");
}

beforeEach(function () {
    Carbon::setTestNow(funnelNow());
    config(['subscriptions.enabled' => true]);
    config(['ai_suggestions.report.excluded_emails' => []]);
    ReportSummaryAgent::fake(['Los registros bajan respecto a la semana anterior.']);
});

it('skips the report and hits no external service when subscriptions are disabled', function () {
    config(['subscriptions.enabled' => false]);

    Http::fake();

    artisan('stats:subscription-funnel')
        ->expectsOutputToContain('Subscriptions are disabled; skipping the subscription funnel report.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('counts registrations, subscriptions and paid conversions per signup week', function () {
    $signup = funnelNow()->subWeeks(10); // old enough to be paid-mature

    funnelUser($signup); // registered only
    funnelUser($signup, ['status' => 'trialing', 'at' => $signup->addDays(2)]); // subscribed, not paid
    funnelUser($signup, ['status' => 'active', 'at' => $signup->addDays(3)]); // subscribed + paid
    // Canceled but lived past the 15d trial -> billed at least once -> paid.
    funnelUser($signup, ['status' => 'canceled', 'at' => $signup->addDays(3), 'endsAt' => $signup->addDays(40)]);
    // Canceled inside the trial window -> never billed -> not paid.
    funnelUser($signup, ['status' => 'canceled', 'at' => $signup->addDays(3), 'endsAt' => $signup->addDays(10)]);

    $row = funnelRow(app(SubscriptionFunnelCollector::class)->collect(), $signup);

    expect($row['registered'])->toBe(5)
        ->and($row['subscribed'])->toBe(4)
        ->and($row['paid'])->toBe(2)
        ->and($row['paidMature'])->toBeTrue()
        ->and($row['subscribedRate'])->toBe(4 / 5)
        ->and($row['paidRate'])->toBe(2 / 5)
        ->and($row['trialToPaidRate'])->toBe(2 / 4);
});

it('ignores subscriptions started after the attribution window', function () {
    $signup = funnelNow()->subWeeks(10);

    funnelUser($signup, ['status' => 'active', 'at' => $signup->addDays(40)]); // beyond 30d window

    $row = funnelRow(app(SubscriptionFunnelCollector::class)->collect(), $signup);

    expect($row['registered'])->toBe(1)
        ->and($row['subscribed'])->toBe(0)
        ->and($row['paid'])->toBe(0);
});

it('keeps the funnel invariant: registered >= subscribed >= paid', function () {
    $signup = funnelNow()->subWeeks(10);

    foreach ($report = app(SubscriptionFunnelCollector::class)->collect()['weeks'] as $row) {
        expect($row['registered'])->toBeGreaterThanOrEqual($row['subscribed'])
            ->and($row['subscribed'])->toBeGreaterThanOrEqual($row['paid']);
    }

    expect($report)->not->toBeEmpty();
});

it('marks young cohorts as not yet mature', function () {
    $recent = funnelNow()->subDays(3); // current week
    $midAged = funnelNow()->subWeeks(5); // past the 30d subscribe window, inside the paid window

    funnelUser($recent);
    funnelUser($midAged, ['status' => 'active', 'at' => $midAged->addDays(2)]);

    $report = app(SubscriptionFunnelCollector::class)->collect();

    $recentRow = funnelRow($report, $recent);
    expect($recentRow['subscribedMature'])->toBeFalse()
        ->and($recentRow['subscribedRate'])->toBeNull()
        ->and($recentRow['paidRate'])->toBeNull();

    $midRow = funnelRow($report, $midAged);
    expect($midRow['subscribedMature'])->toBeTrue()
        ->and($midRow['subscribedRate'])->not->toBeNull()
        ->and($midRow['paidMature'])->toBeFalse()
        ->and($midRow['paidRate'])->toBeNull();
});

it('posts the funnel embed to the configured discord webhook', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    funnelUser(funnelNow()->subWeeks(10), ['status' => 'active', 'at' => funnelNow()->subWeeks(10)->addDays(2)]);

    artisan('stats:subscription-funnel')->assertSuccessful();

    Http::assertSent(function ($request) {
        $embed = $request['embeds'][0];

        return $request->url() === 'https://discord.test/hook'
            && str_contains($embed['title'], 'Embudo de suscripción')
            // Spanish table header and legend, no leftover English.
            && str_contains($embed['description'], 'Semana')
            && collect($embed['fields'])->contains(fn ($field) => $field['name'] === 'Leyenda');
    });
});

it('opens the embed with the AI summary, above the table', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);
    ReportSummaryAgent::fake(['Los pagos suben respecto a la semana anterior.']);

    funnelUser(funnelNow()->subWeeks(10), ['status' => 'active', 'at' => funnelNow()->subWeeks(10)->addDays(2)]);

    artisan('stats:subscription-funnel')->assertSuccessful();

    Http::assertSent(fn ($request) => str_starts_with(
        $request['embeds'][0]['description'],
        "Los pagos suben respecto a la semana anterior.\n\n```",
    ));
});

it('still posts the report when the AI summary fails', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);
    ReportSummaryAgent::fake(fn () => throw new RuntimeException('provider down'));

    funnelUser(funnelNow()->subWeeks(10), ['status' => 'active', 'at' => funnelNow()->subWeeks(10)->addDays(2)]);

    artisan('stats:subscription-funnel')->assertSuccessful();

    Http::assertSent(fn ($request) => str_starts_with($request['embeds'][0]['description'], '```')
        && str_contains($request['embeds'][0]['description'], 'Semana'));
});

it('prints to the console without posting when --no-discord is set', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    funnelUser(funnelNow()->subWeeks(10), ['status' => 'active', 'at' => funnelNow()->subWeeks(10)->addDays(2)]);

    artisan('stats:subscription-funnel', ['--no-discord' => true])
        ->expectsOutputToContain('Los registros bajan respecto a la semana anterior.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('does not overwrite the comparison baseline on a --no-discord run', function () {
    Http::fake();
    Cache::put('report_summary_baseline:subscription-funnel', ['weeks' => ['sentinel']], now()->addDay());

    artisan('stats:subscription-funnel', ['--no-discord' => true])->assertSuccessful();

    expect(Cache::get('report_summary_baseline:subscription-funnel'))->toBe(['weeks' => ['sentinel']]);

    artisan('stats:subscription-funnel')->assertSuccessful();

    expect(Cache::get('report_summary_baseline:subscription-funnel'))->not->toBe(['weeks' => ['sentinel']]);
});
