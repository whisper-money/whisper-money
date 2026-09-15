<?php

use App\Enums\DripEmailType;
use App\Jobs\SendUpdateEmailJob;
use App\Models\User;
use App\Models\UserMailLog;
use App\Support\PriceTiers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Stripe\Collection as StripeCollection;
use Stripe\Service\PriceService;
use Stripe\StripeClient;

use function Pest\Laravel\artisan;

/**
 * Give a user a subscription of their own, in the shape the audience filters
 * read: `ends_at` in the future means cancelled but still running.
 */
function subscribeUser(User $user, string $stripePrice, string $status = 'active', mixed $endsAt = null): User
{
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.$user->id,
        'stripe_status' => $status,
        'stripe_price' => $stripePrice,
        'ends_at' => $endsAt,
    ]);

    return $user;
}

/**
 * Seed the price IDs of the high tier into the cache the command resolves them
 * through, so no test reaches for Stripe.
 *
 * @return list<string>
 */
function cacheHighTierPriceIds(): array
{
    $ids = [];

    foreach (PriceTiers::plansFor('high') as $plan) {
        $id = 'price_'.$plan['stripe_lookup_key'];
        Cache::put("stripe_price_id:{$plan['stripe_lookup_key']}", $id, now()->addHour());
        $ids[] = $id;
    }

    return $ids;
}

beforeEach(function () {
    Queue::fake();

    $viewPath = resource_path('views/mail/updates');
    if (! File::exists($viewPath)) {
        File::makeDirectory($viewPath, 0755, true);
    }

    $testViewContent = <<<'BLADE'
<x-mail::message>
# Test Update

Hello {{ $user->name }},

This is a test update email.

Thanks,
Victor
</x-mail::message>
BLADE;

    File::put(
        resource_path('views/mail/updates/test-update.blade.php'),
        $testViewContent
    );
});

afterEach(function () {
    $testViewPath = resource_path('views/mail/updates/test-update.blade.php');
    if (File::exists($testViewPath)) {
        File::delete($testViewPath);
    }
});

test('command dispatches jobs for all users', function () {
    $users = User::factory()->count(3)->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, 3);

    foreach ($users as $user) {
        Queue::assertPushed(SendUpdateEmailJob::class, function ($job) use ($user) {
            return $job->user->id === $user->id
                && $job->viewName === 'test-update'
                && str_starts_with($job->emailIdentifier, 'test-2026_force_')
                && $job->subject === 'Update from Whisper Money';
        });
    }
});

test('command skips deleted users', function () {
    $activeUser = User::factory()->create();
    $deletedUser = User::factory()->create();
    $deletedUser->delete();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, function ($job) use ($activeUser) {
        return $job->user->id === $activeUser->id;
    });

    Queue::assertNotPushed(SendUpdateEmailJob::class, function ($job) use ($deletedUser) {
        return $job->user->id === $deletedUser->id;
    });

    Queue::assertCount(1);
});

test('command excludes demo account when flag is set', function () {
    $regularUser = User::factory()->create();
    $demoUser = User::factory()->create(['email' => config('app.demo.email')]);

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--exclude-demo' => true,
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, function ($job) use ($regularUser) {
        return $job->user->id === $regularUser->id;
    });

    Queue::assertNotPushed(SendUpdateEmailJob::class, function ($job) use ($demoUser) {
        return $job->user->id === $demoUser->id;
    });

    Queue::assertCount(1);
});

test('command fails when view does not exist', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'non-existent-view',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertFailed();

    Queue::assertNothingPushed();
});

test('command uses custom subject when provided', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--subject' => 'Custom Subject Here',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, function ($job) {
        return $job->subject === 'Custom Subject Here';
    });
});

test('job is dispatched to emails queue', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushedOn('emails', SendUpdateEmailJob::class);
});

test('command handles empty user database gracefully', function () {
    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertNothingPushed();
});

test('job skips users who already received the update', function () {
    Queue::fake([]);

    $user = User::factory()->create();

    UserMailLog::create([
        'user_id' => $user->id,
        'email_type' => DripEmailType::Update,
        'email_identifier' => 'test-2026',
        'sent_at' => now(),
    ]);

    $job = new SendUpdateEmailJob($user, 'test-update', 'test-2026');
    $job->handle();

    expect(UserMailLog::where('user_id', $user->id)->count())->toBe(1);
});

test('job creates mail log entry after sending', function () {
    Queue::fake([]);

    $user = User::factory()->create();

    expect(UserMailLog::where('user_id', $user->id)->exists())->toBeFalse();

    $job = new SendUpdateEmailJob($user, 'test-update', 'test-2026');
    $job->handle();

    expect(UserMailLog::where('user_id', $user->id)
        ->where('email_type', DripEmailType::Update)
        ->where('email_identifier', 'test-2026')
        ->exists())->toBeTrue();
});

test('job sends email with correct view and user data', function () {
    Queue::fake([]);

    $user = User::factory()->create(['name' => 'John Doe']);

    $job = new SendUpdateEmailJob($user, 'test-update', 'test-2026', 'Custom Subject');
    $job->handle();

    expect(UserMailLog::where('user_id', $user->id)->exists())->toBeTrue();
});

test('command requires confirmation by default', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
    ])->expectsConfirmation("About to send 'test-2026' email to 1 user(s). Continue?", 'no')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('command skips confirmation with force flag', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->doesntExpectOutput('Continue?')
        ->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, 1);
});

test('command queues a large batch for today by default', function () {
    // The default per-day is the SES daily quota, so nothing is held back for
    // tomorrow until a send is bigger than SES will accept in a day.
    User::factory()->count(150)->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, 150);

    $delays = collect(Queue::pushedJobs()[SendUpdateEmailJob::class])
        ->map(fn (array $pushed) => $pushed['job']->delay);

    expect($delays->filter(fn ($delay) => $delay->isSameDay(now()))->count())->toBe(150);
});

test('audience unsubscribed leaves out subscribers and users on a trial', function () {
    config(['subscriptions.enabled' => true]);
    cacheHighTierPriceIds();

    $unsubscribed = User::factory()->create();
    $ended = subscribeUser(User::factory()->create(), 'price_low', 'canceled', now()->subDay());
    $onTrial = User::factory()->create(['trial_ends_at' => now()->addWeek()]);
    $subscribed = subscribeUser(User::factory()->create(), 'price_low');
    $pastDue = subscribeUser(User::factory()->create(), 'price_low', 'past_due');
    // Cancelled but still running: /subscribe would bounce them to the
    // dashboard, and the cancelling-low-price email is the one that fits them.
    $cancelling = subscribeUser(User::factory()->create(), 'price_low', 'active', now()->addWeek());

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--audience' => 'unsubscribed',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertCount(2);

    foreach ([$unsubscribed, $ended] as $user) {
        Queue::assertPushed(SendUpdateEmailJob::class, fn ($job) => $job->user->id === $user->id);
    }

    foreach ([$onTrial, $subscribed, $pastDue, $cancelling] as $user) {
        Queue::assertNotPushed(SendUpdateEmailJob::class, fn ($job) => $job->user->id === $user->id);
    }
});

test('audience unsubscribed refuses to send while subscriptions are disabled', function () {
    // Otherwise hasActiveSubscriptionOrTrial() answers false for everyone and
    // the whole database reads as unsubscribed.
    config(['subscriptions.enabled' => false]);

    subscribeUser(User::factory()->create(), 'price_low');

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--audience' => 'unsubscribed',
        '--force' => true,
    ])->assertFailed();

    Queue::assertNothingPushed();
});

test('audience cancelling-low-price only mails cancelling users still on the old price', function () {
    [$highMonthly] = cacheHighTierPriceIds();

    $cancellingOnLowPrice = subscribeUser(User::factory()->create(), 'price_low', 'active', now()->addWeek());
    $cancellingOnTrial = subscribeUser(User::factory()->create(), 'price_low', 'trialing', now()->addDays(3));
    $cancellingOnHighPrice = subscribeUser(User::factory()->create(), $highMonthly, 'active', now()->addWeek());
    $alreadyEnded = subscribeUser(User::factory()->create(), 'price_low', 'canceled', now()->subDay());
    $stillSubscribed = subscribeUser(User::factory()->create(), 'price_low');
    $neverSubscribed = User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--audience' => 'cancelling-low-price',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertCount(2);

    foreach ([$cancellingOnLowPrice, $cancellingOnTrial] as $user) {
        Queue::assertPushed(SendUpdateEmailJob::class, fn ($job) => $job->user->id === $user->id);
    }

    foreach ([$cancellingOnHighPrice, $alreadyEnded, $stillSubscribed, $neverSubscribed] as $user) {
        Queue::assertNotPushed(SendUpdateEmailJob::class, fn ($job) => $job->user->id === $user->id);
    }
});

test('an audience refuses to send when a high tier price cannot be resolved', function (string $audience) {
    config(['subscriptions.enabled' => true]);

    // With no high price IDs to exclude, the A/B cohort already on the high
    // price would be mailed about a price increase that does not apply to them.
    $prices = Mockery::mock(PriceService::class);
    $prices->shouldReceive('all')->andReturn(StripeCollection::constructFrom([
        'object' => 'list',
        'has_more' => false,
        'data' => [],
    ]));

    $stripe = Mockery::mock(StripeClient::class);
    $stripe->prices = $prices;
    app()->bind(StripeClient::class, fn () => $stripe);

    subscribeUser(User::factory()->create(), 'price_low', 'active', now()->addWeek());

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--audience' => $audience,
        '--force' => true,
    ])->assertFailed();

    Queue::assertNothingPushed();
})->with(['unsubscribed', 'active-low-price', 'cancelling-low-price']);

test('audience active-low-price only mails live subscriptions on the old price', function () {
    [$highMonthly] = cacheHighTierPriceIds();

    $monthly = subscribeUser(User::factory()->create(), 'price_low');
    $onTrial = subscribeUser(User::factory()->create(), 'price_low', 'trialing');
    $pastDue = subscribeUser(User::factory()->create(), 'price_low', 'past_due');
    $onHighPrice = subscribeUser(User::factory()->create(), $highMonthly);
    $cancelling = subscribeUser(User::factory()->create(), 'price_low', 'active', now()->addWeek());
    $ended = subscribeUser(User::factory()->create(), 'price_low', 'canceled', now()->subDay());
    $neverSubscribed = User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--audience' => 'active-low-price',
        '--force' => true,
    ])->assertSuccessful();

    Queue::assertCount(3);

    foreach ([$monthly, $onTrial, $pastDue] as $user) {
        Queue::assertPushed(SendUpdateEmailJob::class, fn ($job) => $job->user->id === $user->id);
    }

    // A cancelled subscription is not collectable, so it belongs to the other
    // email, and the high-price cohort is never in a price increase audience.
    foreach ([$onHighPrice, $cancelling, $ended, $neverSubscribed] as $user) {
        Queue::assertNotPushed(SendUpdateEmailJob::class, fn ($job) => $job->user->id === $user->id);
    }
});

test('nobody who has been on the high price is in any price increase audience', function (string $audience) {
    config(['subscriptions.enabled' => true]);
    [$highMonthly] = cacheHighTierPriceIds();

    // One user per shape the audiences look for, each of them also holding a
    // high-price subscription. None of them may be mailed about the increase.
    $live = subscribeUser(User::factory()->create(), $highMonthly);
    $cancelling = subscribeUser(User::factory()->create(), $highMonthly, 'active', now()->addWeek());
    $ended = subscribeUser(User::factory()->create(), $highMonthly, 'canceled', now()->subDay());

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--audience' => $audience,
        '--force' => true,
    ])->assertSuccessful();

    foreach ([$live, $cancelling, $ended] as $user) {
        Queue::assertNotPushed(SendUpdateEmailJob::class, fn ($job) => $job->user->id === $user->id);
    }
})->with(['unsubscribed', 'active-low-price', 'cancelling-low-price']);

test('command fails on an unknown audience', function () {
    User::factory()->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--audience' => 'everyone-i-like',
        '--force' => true,
    ])->assertFailed();

    Queue::assertNothingPushed();
});

test('per-day option decides how many emails go out each day', function () {
    User::factory()->count(5)->create();

    artisan('email:update', [
        'view' => 'test-update',
        'identifier' => 'test-2026',
        '--per-day' => 2,
        '--force' => true,
    ])->assertSuccessful();

    $delays = collect(Queue::pushedJobs()[SendUpdateEmailJob::class])
        ->map(fn (array $pushed) => $pushed['job']->delay);

    expect($delays)->toHaveCount(5)
        ->and($delays->filter(fn ($delay) => $delay->isSameDay(now()))->count())->toBe(2)
        ->and($delays->filter(fn ($delay) => $delay->isSameDay(now()->addDay()))->count())->toBe(2)
        ->and($delays->filter(fn ($delay) => $delay->isSameDay(now()->addDays(2)))->count())->toBe(1);
});
