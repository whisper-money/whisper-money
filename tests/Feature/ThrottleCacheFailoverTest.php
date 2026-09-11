<?php

use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The deadlock InnoDB raises when concurrent requests in the same throttle
 * bucket race to `insert ignore` the same rate-limiter row.
 */
function deadlockOnRateLimiterInsert(): QueryException
{
    return new QueryException(
        'mysql',
        'insert ignore into `cache` (`key`, `value`, `expiration`) values (?, ?, ?)',
        ['whisper-money-cache-c651a105:timer', 'i:1789047427;', 1789047427],
        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
    );
}

/**
 * Make every write to the "database" cache store deadlock, the way MySQL does
 * to whichever request it picks as the victim.
 *
 * Only the writes throw — reads keep answering, which is the real shape of the
 * failure: the row is fine, the insert is what loses the race.
 */
function deadlockDatabaseCacheWrites(): void
{
    Cache::extend('database', fn () => new Repository(new class extends ArrayStore
    {
        public function add($key, $value, $seconds)
        {
            throw deadlockOnRateLimiterInsert();
        }

        public function put($key, $value, $seconds)
        {
            throw deadlockOnRateLimiterInsert();
        }

        public function increment($key, $value = 1)
        {
            throw deadlockOnRateLimiterInsert();
        }
    }));

    // The manager memoizes resolved stores, and the failover store asks it for
    // "database" on every operation.
    Cache::forgetDriver(['database', 'failover']);

    // Production runs CACHE_STORE=database, so a limiter with no store of its
    // own sits straight on the deadlocking one. This suite runs "array", which
    // never reaches the insert and would pass no matter what we configured —
    // so stand the default back up while the limiter resolves its store.
    config(['cache.default' => 'database']);

    app()->forgetInstance(RateLimiter::class);
    app(RateLimiter::class);

    // The limiter holds its repository from here on, so hand the rest of the
    // app back the array store instead of a cache that throws on every write.
    config(['cache.default' => 'array']);
}

test('a throttled request survives a rate-limiter cache deadlock (regression for PHP-LARAVEL-J)', function () {
    deadlockDatabaseCacheWrites();

    $this->actingAs(User::factory()->onboarded()->create())
        ->getJson('api/import/data')
        ->assertOk();
});

test('the deadlock is logged rather than degrading in silence', function () {
    deadlockDatabaseCacheWrites();
    Log::spy();

    $this->actingAs(User::factory()->onboarded()->create())->getJson('api/import/data');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'Cache store failed over'
            && $context['store'] === 'database'
            && str_contains($context['error'], 'Deadlock found when trying to get lock'));
});

test('throttling still rejects once the limit is reached', function () {
    $user = User::factory()->create();

    // settings/password is throttle:6,1. The 7th attempt must still be turned
    // away, so routing the limiter through the failover store cannot have
    // quietly stopped it counting hits.
    foreach (range(1, 6) as $ignored) {
        $this->actingAs($user)->put('settings/password', [])->assertStatus(302);
    }

    $this->actingAs($user)->put('settings/password', [])->assertStatus(429);
});
