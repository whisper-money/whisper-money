<?php

use App\Models\BankingConnection;
use App\Models\StuckCohortSnapshot;
use App\Models\User;
use App\Services\Stats\StuckCohortReportCollector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

/**
 * Create an onboarded user with a non-deleted banking connection but no valid
 * subscription: the definition of a paywall-stuck user.
 */
function stuckUser(): User
{
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->for($user)->create();

    return $user;
}

/**
 * Create an onboarded user with a banking connection and a subscription in the
 * given status (defaults to a valid, active one).
 *
 * @param  array<string, mixed>  $attributes
 */
function subscribedUser(string $status = 'active', array $attributes = []): User
{
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->for($user)->create();

    $user->subscriptions()->create(array_merge([
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(12),
        'stripe_status' => $status,
        'stripe_price' => 'price_test',
    ], $attributes));

    return $user;
}

beforeEach(function () {
    config(['services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook']);
    Http::fake(['discord.test/*' => Http::response('', 204)]);
});

it('counts onboarded banked users without a valid subscription as stuck', function () {
    // Stuck: onboarded + bank + no valid subscription.
    stuckUser();
    stuckUser();
    stuckUser();

    // Not stuck: valid subscriptions in their various forms.
    subscribedUser('active');
    subscribedUser('trialing');
    subscribedUser('past_due');
    subscribedUser('canceled', ['ends_at' => now()->addWeek()]); // still in grace period

    // Stuck: canceled subscription whose grace period already lapsed.
    subscribedUser('canceled', ['ends_at' => now()->subDay()]);

    // Excluded from denominator: never finished onboarding.
    $notOnboarded = User::factory()->notOnboarded()->create();
    BankingConnection::factory()->for($notOnboarded)->create();

    // Not stuck: onboarded but never connected a bank.
    User::factory()->onboarded()->create();

    // Not stuck: soft-deleted connection does not count.
    $deletedConnectionUser = User::factory()->onboarded()->create();
    BankingConnection::factory()->for($deletedConnectionUser)->create()->delete();

    $report = app(StuckCohortReportCollector::class)->collect();

    // Stuck users: 3 plain + 1 lapsed-canceled = 4.
    // Onboarded denominator: 4 stuck + 4 valid-sub + 1 no-bank + 1 deleted-conn = 10.
    expect($report['snapshot']->stuck_count)->toBe(4)
        ->and($report['snapshot']->onboarded_count)->toBe(10)
        ->and((float) $report['snapshot']->stuck_pct)->toBe(40.0);
});

it('persists the snapshot and reports no delta on the first run', function () {
    stuckUser();
    subscribedUser('active');

    $report = app(StuckCohortReportCollector::class)->collect();

    expect(StuckCohortSnapshot::query()->count())->toBe(1)
        ->and($report['previous'])->toBeNull()
        ->and($report['pctDelta'])->toBeNull()
        ->and($report['stuckDelta'])->toBeNull();

    $snapshot = StuckCohortSnapshot::query()->sole();
    expect($snapshot->stuck_count)->toBe(1)
        ->and($snapshot->onboarded_count)->toBe(2)
        ->and((float) $snapshot->stuck_pct)->toBe(50.0);
});

it('reports the delta against the most recent previous snapshot', function () {
    StuckCohortSnapshot::query()->create([
        'date' => today()->subWeek(),
        'onboarded_count' => 10,
        'stuck_count' => 2,
        'stuck_pct' => 20.0,
    ]);

    // This week: 6 stuck out of 10 onboarded = 60%.
    foreach (range(1, 6) as $ignored) {
        stuckUser();
    }
    foreach (range(1, 4) as $ignored) {
        subscribedUser('active');
    }

    $report = app(StuckCohortReportCollector::class)->collect();

    expect($report['snapshot']->stuck_pct)->toBe(60.0)
        ->and($report['previous'])->not->toBeNull()
        ->and($report['pctDelta'])->toBe(40.0)
        ->and($report['stuckDelta'])->toBe(4);
});

it('upserts a single snapshot per day across runs', function () {
    stuckUser();

    app(StuckCohortReportCollector::class)->collect();
    app(StuckCohortReportCollector::class)->collect();

    expect(StuckCohortSnapshot::query()->whereDate('date', today())->count())->toBe(1);
});

it('prints to the console without posting when --no-discord is set', function () {
    stuckUser();
    subscribedUser('active');

    artisan('stats:stuck-cohort-report', ['--no-discord' => true])->assertSuccessful();

    Http::assertNothingSent();
});

it('posts the stuck cohort embed to the configured discord webhook', function () {
    stuckUser();
    subscribedUser('active');

    artisan('stats:stuck-cohort-report')->assertSuccessful();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://discord.test/hook'
            && isset($request['embeds'][0]['title'])
            && str_contains($request['embeds'][0]['title'], 'Stuck Cohort')
            && str_contains($request['embeds'][0]['description'], 'Stuck       1')
            && str_contains($request['embeds'][0]['description'], 'Onboarded   2')
            && str_contains($request['embeds'][0]['description'], 'Stuck rate  50%');
    });
});

it('reports the week-over-week delta in the discord embed', function () {
    StuckCohortSnapshot::query()->create([
        'date' => today()->subWeek(),
        'onboarded_count' => 4,
        'stuck_count' => 1,
        'stuck_pct' => 25.0,
    ]);

    foreach (range(1, 2) as $ignored) {
        stuckUser();
    }
    foreach (range(1, 2) as $ignored) {
        subscribedUser('active');
    }

    artisan('stats:stuck-cohort-report')->assertSuccessful();

    Http::assertSent(function ($request) {
        $description = $request['embeds'][0]['description'] ?? '';

        return str_contains($description, '+25 pp')
            && str_contains($description, '+1 stuck');
    });
});

it('falls back to the default discord webhook when no dedicated one is set', function () {
    config([
        'services.discord.ai_cohort_webhook_url' => null,
        'services.discord.webhook_url' => 'https://discord.test/default',
    ]);

    stuckUser();

    artisan('stats:stuck-cohort-report')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://discord.test/default');
});
