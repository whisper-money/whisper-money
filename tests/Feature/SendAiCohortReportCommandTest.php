<?php

use App\Models\Account;
use App\Models\AiConsent;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiCohortReportCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

function referenceNow(): CarbonImmutable
{
    return CarbonImmutable::create(2026, 6, 17, 12, 0, 0, 'UTC');
}

/**
 * Create an eligible (or deliberately ineligible) user with transactions, and
 * optionally a consent and a subscription, all anchored to the given signup.
 *
 * @param  array<string, mixed>  $opts
 */
function cohortUser(CarbonImmutable $signup, array $opts = []): User
{
    $user = User::factory()->create([
        'email' => $opts['email'] ?? fake()->unique()->safeEmail(),
        'created_at' => $signup,
        'last_active_at' => $opts['lastActiveAt'] ?? null,
    ]);

    $account = Account::factory()->for($user)->create();
    $category = Category::factory()->for($user)->create();

    Transaction::factory()->count($opts['transactions'] ?? 3)->for($user)->for($account)->create([
        'category_id' => $category->id,
        'created_at' => $signup->addDay(),
    ]);

    if (! empty($opts['consentAt'])) {
        AiConsent::factory()->for($user)->create(['accepted_at' => $opts['consentAt']]);
    }

    if (! empty($opts['subscription'])) {
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(12),
            'stripe_status' => $opts['subscription']['status'],
            'stripe_price' => 'price_test',
            'created_at' => $opts['subscription']['at'],
        ]);
    }

    return $user;
}

/**
 * @param  array{weeks: list<array<string, mixed>>}  $report
 * @return array<string, mixed>
 */
function rowForWeek(array $report, CarbonImmutable $signup): array
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
    Carbon::setTestNow(referenceNow());
    config(['ai_suggestions.eligibility_min_transactions' => 3]);
    config(['ai_suggestions.report.excluded_emails' => []]);
});

it('only counts users who imported enough transactions within their first week', function () {
    $signup = referenceNow()->subWeeks(6);

    cohortUser($signup, ['transactions' => 3, 'lastActiveAt' => $signup->addDays(20)]); // eligible, retained
    cohortUser($signup, ['transactions' => 3, 'lastActiveAt' => $signup->addDays(2)]);  // eligible, churned early
    cohortUser($signup, ['transactions' => 2, 'lastActiveAt' => $signup->addDays(20)]); // ineligible (<3 txns)

    // 3 transactions, but imported on day 10 — outside the 7-day eligibility window.
    $late = cohortUser($signup, ['transactions' => 0, 'lastActiveAt' => $signup->addDays(20)]);
    $account = Account::factory()->for($late)->create();
    Transaction::factory()->count(3)->for($late)->for($account)->create(['created_at' => $signup->addDays(10)]);

    $report = app(AiCohortReportCollector::class)->collect();
    $row = rowForWeek($report, $signup);

    expect($row['eligible'])->toBe(2)
        ->and($row['retained'])->toBe(1)
        ->and($row['retainedRate'])->toBe(0.5)
        ->and($row['retentionMature'])->toBeTrue();
});

it('counts trial starts within 14 days and active paid conversions within 30 days', function () {
    $signup = referenceNow()->subWeeks(6);

    // Trialing subscription started at signup: counts as trial, not as paid.
    cohortUser($signup, [
        'transactions' => 3,
        'subscription' => ['status' => 'trialing', 'at' => $signup->addDays(2)],
    ]);

    // Active subscription within 30 days: counts as both trial and paid.
    cohortUser($signup, [
        'transactions' => 3,
        'subscription' => ['status' => 'active', 'at' => $signup->addDays(5)],
    ]);

    // Active subscription, but only after day 40: outside both windows.
    cohortUser($signup, [
        'transactions' => 3,
        'subscription' => ['status' => 'active', 'at' => $signup->addDays(40)],
    ]);

    $report = app(AiCohortReportCollector::class)->collect();
    $row = rowForWeek($report, $signup);

    expect($row['eligible'])->toBe(3)
        ->and($row['trial'])->toBe(2)
        ->and($row['paid'])->toBe(1)
        ->and($row['paidMature'])->toBeTrue();
});

it('derives the release anchor from the first consent and splits cohorts pre/post', function () {
    config(['ai_suggestions.report.excluded_emails' => ['staff@whisper.test']]);

    $release = referenceNow()->subWeeks(4);

    // Staff account plants the release-anchor consent; excluded from cohort metrics.
    cohortUser($release, [
        'email' => 'staff@whisper.test',
        'transactions' => 3,
        'consentAt' => $release,
    ]);

    $before = referenceNow()->subWeeks(6);
    $after = referenceNow()->subWeeks(2);
    cohortUser($before, ['transactions' => 3]);
    cohortUser($after, ['transactions' => 3]);

    $report = app(AiCohortReportCollector::class)->collect();

    expect($report['releaseWeek'])->toBe($release->startOfWeek(CarbonImmutable::MONDAY)->format('o-\WW'))
        ->and(rowForWeek($report, $before)['phase'])->toBe('pre')
        ->and(rowForWeek($report, $after)['phase'])->toBe('post')
        // Staff account was excluded, so the release week has no eligible users.
        ->and(rowForWeek($report, $release)['eligible'])->toBe(0);
});

it('marks cohorts as not mature until the metric horizon has elapsed', function () {
    $recent = referenceNow()->subDays(3); // current week — too young for any 14d metric
    $midAged = referenceNow()->subWeeks(3); // older than 14d, younger than 30d

    cohortUser($recent, ['transactions' => 3, 'lastActiveAt' => $recent->addDay()]);
    cohortUser($midAged, ['transactions' => 3, 'lastActiveAt' => $midAged->addDays(20)]);

    $report = app(AiCohortReportCollector::class)->collect();

    $recentRow = rowForWeek($report, $recent);
    expect($recentRow['retentionMature'])->toBeFalse()
        ->and($recentRow['retainedRate'])->toBeNull()
        ->and($recentRow['paidRate'])->toBeNull();

    $midRow = rowForWeek($report, $midAged);
    expect($midRow['retentionMature'])->toBeTrue()
        ->and($midRow['retainedRate'])->not->toBeNull()
        ->and($midRow['paidMature'])->toBeFalse()
        ->and($midRow['paidRate'])->toBeNull();
});

it('flags weeks whose eligible volume is an outlier surge', function () {
    $surgeWeek = referenceNow()->subWeeks(5);
    $quietWeeks = [referenceNow()->subWeeks(8), referenceNow()->subWeeks(7), referenceNow()->subWeeks(6)];

    foreach ($quietWeeks as $week) {
        cohortUser($week, ['transactions' => 3]);
    }

    for ($i = 0; $i < 6; $i++) {
        cohortUser($surgeWeek, ['transactions' => 3]);
    }

    $report = app(AiCohortReportCollector::class)->collect();

    expect(rowForWeek($report, $surgeWeek)['surge'])->toBeTrue()
        ->and(rowForWeek($report, $quietWeeks[0])['surge'])->toBeFalse();
});

it('posts the cohort report embed to the configured discord webhook', function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    cohortUser(referenceNow()->subWeeks(6), ['transactions' => 3, 'lastActiveAt' => referenceNow()->subWeeks(3)]);

    artisan('stats:ai-cohort-report')->assertSuccessful();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.test/hook'
            && isset($request['embeds'][0]['title'])
            && str_contains($request['embeds'][0]['title'], 'AI Suggestions');
    });
});

it('falls back to the default discord webhook when no dedicated one is set', function () {
    config([
        'services.discord.ai_cohort_webhook_url' => null,
        'services.discord.webhook_url' => 'https://discord.test/default',
    ]);
    Http::fake(['discord.test/*' => Http::response('', 204)]);

    artisan('stats:ai-cohort-report')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/default');
});
