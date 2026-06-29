<?php

use App\Models\User;
use App\Services\Stats\SubscriptionFunnelCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
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
    config(['ai_suggestions.report.excluded_emails' => []]);
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
        return $request->url() === 'https://discord.test/hook'
            && isset($request['embeds'][0]['title'])
            && str_contains($request['embeds'][0]['title'], 'Subscription Funnel');
    });
});

it('prints to the console without posting when --no-discord is set', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    funnelUser(funnelNow()->subWeeks(10), ['status' => 'active', 'at' => funnelNow()->subWeeks(10)->addDays(2)]);

    artisan('stats:subscription-funnel', ['--no-discord' => true])->assertSuccessful();

    Http::assertNothingSent();
});
