<?php

use App\Jobs\Drip\SendAchievementsEmailJob;
use App\Models\Achievement;
use App\Models\User;
use App\Notifications\AchievementUnlocked;
use App\Services\Achievements\Awarder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * Two requests for the same reader, awarding the same medal at the same time.
 *
 * A visit medal is settled on the request that earns it, and a reader has more
 * than one request in flight at once — two tabs, a prefetch alongside the
 * navigation it was prefetching. Both read the medals already held, both find
 * the same one missing, and both insert it. The unique index settles it; what
 * matters here is that the loser neither throws away the medal it was told to
 * award nor tells the reader a second time about one the winner announced.
 */

/**
 * A reader the sweep has already been through, so a visit medal is settled on
 * the request rather than left to the night.
 *
 * @param  array<string, mixed>  $attributes
 */
function racingReader(array $attributes = []): User
{
    $user = User::factory()->onboarded()->create([
        'last_active_at' => now(),
        ...$attributes,
    ]);

    Achievement::factory()->key('transactions.1')->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    return $user;
}

/**
 * The other request, landing in the window the race lives in: the row for $key
 * is written the moment the awarder has finished reading which medals are
 * already held, and so is missing from the list it goes on to diff against.
 *
 * Hung on that read rather than on the insert itself, because the insert runs
 * inside the savepoint `createOrFirst` wraps its attempt in: a row written
 * there would be rolled back along with the failed attempt, which is not what a
 * row committed by another connection does.
 */
function otherRequestWrites(User $user, string $key): void
{
    $written = false;

    DB::listen(function (QueryExecuted $query) use ($user, $key, &$written): void {
        if ($written || ! str_contains($query->sql, 'select `key` from `achievements`')) {
            return;
        }

        $written = true;

        Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
        ]);
    });
}

it('leaves the medal to the request that got there first', function (): void {
    Notification::fake();
    Queue::fake();

    // Three days in a row, which is the first visit rung.
    $user = racingReader(['visit_streak' => 3, 'longest_visit_streak' => 3]);
    otherRequestWrites($user, 'visits.1');

    $recorded = app(Awarder::class)->awardVisitRuns($user);

    expect($recorded)->toBeEmpty()
        ->and($user->achievements()->where('key', 'visits.1')->count())->toBe(1);

    Notification::assertNotSentTo($user, AchievementUnlocked::class);
    Queue::assertNotPushed(SendAchievementsEmailJob::class);
});

it('still announces the medal the other request did not take', function (): void {
    Notification::fake();
    Queue::fake();

    // A week in a row clears both the three-day rung and the seven-day one.
    $user = racingReader(['visit_streak' => 7, 'longest_visit_streak' => 7]);
    otherRequestWrites($user, 'visits.1');

    $recorded = app(Awarder::class)->awardVisitRuns($user);

    expect($recorded->pluck('key')->all())->toBe(['visits.2']);

    Notification::assertSentToTimes($user, AchievementUnlocked::class, 1);
    Queue::assertPushed(SendAchievementsEmailJob::class, 1);
});
