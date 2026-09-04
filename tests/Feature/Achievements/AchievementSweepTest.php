<?php

use App\Enums\CategoryType;
use App\Features\Achievements;
use App\Jobs\Drip\SendAchievementsEmailJob;
use App\Models\Account;
use App\Models\Achievement;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AchievementsWelcome;
use App\Notifications\AchievementUnlocked;
use App\Services\Achievements\Awarder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

/*
 * The sweep, end to end: real transactions in, rows out, and who gets told.
 *
 * The rules themselves are covered against a hand-built history elsewhere. What
 * matters here is that a life read out of the database lands on the right
 * months, that a medal is written once and never again, and that a backfill
 * arrives as one welcome rather than as twenty separate congratulations.
 */

function reader(): User
{
    return User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
}

/**
 * A month with money coming in and some of it set aside.
 */
function recordMonth(User $user, string $month, int $income, int $saved): void
{
    $account = $user->accounts()->first() ?? Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'currency_code' => 'EUR',
    ]);

    $categories = collect([CategoryType::Income, CategoryType::Savings])
        ->mapWithKeys(fn (CategoryType $type): array => [$type->value => Category::query()
            ->firstOrCreate(
                ['user_id' => $user->id, 'type' => $type, 'name' => $type->value],
                ['space_id' => $user->activeSpace()->id],
            )]);

    $date = $month.'-05';

    Transaction::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'account_id' => $account->id,
        'category_id' => $categories[CategoryType::Income->value]->id,
        'transaction_date' => $date,
        'amount' => $income,
        'currency_code' => 'EUR',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'account_id' => $account->id,
        'category_id' => $categories[CategoryType::Savings->value]->id,
        'transaction_date' => $date,
        'amount' => -$saved,
        'currency_code' => 'EUR',
    ]);
}

it('dates a medal to the month it happened, not to the day it was noticed', function (): void {
    $user = reader();
    recordMonth($user, now()->subMonths(8)->format('Y-m'), 300000, 150000);

    app(Awarder::class)->sweep($user);

    $first = $user->achievements()->where('key', 'transactions.1')->firstOrFail();

    expect($first->achieved_on->format('Y-m'))->toBe(now()->subMonths(8)->format('Y-m'))
        ->and($user->achievements()->where('key', 'monthly_saving.2')->first()?->value)->toBe(150000);
});

it('announces a backfill once instead of medal by medal', function (): void {
    Notification::fake();
    $user = reader();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 150000);

    $recorded = app(Awarder::class)->sweep($user);

    expect($recorded->count())->toBeGreaterThan(1);

    Notification::assertSentTo($user, AchievementsWelcome::class, function (AchievementsWelcome $notification) use ($recorded): bool {
        return $notification->count === $recorded->count();
    });
    Notification::assertNotSentTo($user, AchievementUnlocked::class);
});

it('gives every later medal a row of its own, and the day one email', function (): void {
    Notification::fake();
    Queue::fake();
    $user = reader();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 15000);

    app(Awarder::class)->sweep($user);

    // A better month arrives, clearing the next rung.
    recordMonth($user, now()->subMonths(2)->format('Y-m'), 400000, 260000);
    $recorded = app(Awarder::class)->sweep($user);

    expect($recorded)->not->toBeEmpty();

    Notification::assertSentToTimes($user, AchievementUnlocked::class, $recorded->count());
    Queue::assertPushed(SendAchievementsEmailJob::class, 1);
});

it('records a medal once, however many times the sweep runs', function (): void {
    $user = reader();
    recordMonth($user, now()->subMonths(4)->format('Y-m'), 300000, 150000);

    $first = app(Awarder::class)->sweep($user)->count();

    expect(app(Awarder::class)->sweep($user))->toBeEmpty()
        ->and($user->achievements()->count())->toBe($first);
});

it('keeps a medal after the money that earned it is gone', function (): void {
    $user = reader();
    recordMonth($user, now()->subMonths(5)->format('Y-m'), 300000, 150000);
    app(Awarder::class)->sweep($user);

    $before = $user->achievements()->pluck('key')->sort()->values();

    $user->transactions()->delete();
    app(Awarder::class)->sweep($user);

    expect($user->achievements()->pluck('key')->sort()->values())->toEqual($before);
});

it('sends no email to a reader who asked for none', function (): void {
    Queue::fake();
    $user = reader();
    $user->setting()->updateOrCreate(['user_id' => $user->id], ['notify_achievements' => false]);
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 15000);
    app(Awarder::class)->sweep($user);

    recordMonth($user, now()->subMonths(2)->format('Y-m'), 400000, 260000);
    app(Awarder::class)->sweep($user);

    Queue::assertNotPushed(SendAchievementsEmailJob::class);
});

it('finds nothing for a reader with no transactions', function (): void {
    expect(app(Awarder::class)->sweep(reader()))->toBeEmpty();
});

it('measures a reader in a currency it has no ladder for against the fallback', function (): void {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response(['usd' => ['jpy' => 150.0, 'eur' => 0.9, 'usd' => 1.0]]),
    ]);

    $user = reader();
    $user->forceFill(['currency_code' => 'JPY'])->save();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 150000);

    app(Awarder::class)->sweep($user);

    $medal = $user->achievements()->where('key', 'monthly_saving.1')->first();

    expect($medal?->currency_code)->toBe('USD');
});

it('only sweeps a reader the feature is on for', function (): void {
    config()->set('achievements.enabled', false);
    $user = reader();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 150000);

    $this->artisan('achievements:sweep', ['--user' => $user->email])->assertSuccessful();

    expect(Achievement::query()->count())->toBe(0);

    // Pennant stored the "off" it resolved a moment ago, so turning the config
    // on only reaches a scope that has not been resolved yet — the same step
    // the rollout has to take in production.
    config()->set('achievements.enabled', true);
    Feature::purge(Achievements::class);

    $this->artisan('achievements:sweep', ['--user' => $user->email])->assertSuccessful();

    expect(Achievement::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0);
});

it('records nothing when a foreign balance cannot be converted', function (): void {
    // No rate for the day, and the API cannot be reached for one either.
    Http::fake(['cdn.jsdelivr.net/*' => Http::response([], 500)]);

    $user = reader();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 150000);

    // A second account holding money in a currency the ladder is not in.
    Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'currency_code' => 'BTC',
    ]);

    // A milestone dated from a half-converted history would be wrong forever,
    // so the sweep reports the reader and moves on rather than guessing.
    $this->artisan('achievements:sweep', ['--user' => $user->email])
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    expect($user->achievements()->count())->toBe(0);
});
