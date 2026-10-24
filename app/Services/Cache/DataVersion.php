<?php

namespace App\Services\Cache;

use App\Models\Space;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The per-user "data version" token every user-data cache key is built from.
 *
 * Any write that can change what a user sees bumps their token, which orphans
 * every cache entry built under the old one. The token is a random ULID
 * written with a plain SET (never INCR): if the store evicts the key, a reader
 * mints a fresh token and simply misses, instead of a counter restarting at 1
 * and colliding with entries still cached under the old value.
 *
 * Bumps run after the surrounding transaction commits, so a reader can never
 * pick up the new token and then read data that is not committed yet; a rolled
 * back transaction bumps nothing. Bumps queued while committing or while
 * {@see deferBumps()} runs are deduplicated and flushed in one pass.
 */
#[Scoped]
final class DataVersion
{
    /** The token every key also carries, bumped when exchange rates change. */
    public const string RATES = 'rates';

    private const string KEY_PREFIX = 'wm:dv:';

    /** @var array<string, true> */
    private array $pending = [];

    private int $deferDepth = 0;

    /** @var array<string, list<string>> */
    private array $spaceMembers = [];

    public static function bumpUser(User|string $user): void
    {
        self::bumpUsers([$user]);
    }

    /**
     * @param  iterable<User|string>  $users
     */
    public static function bumpUsers(iterable $users): void
    {
        $ids = [];

        foreach ($users as $user) {
            $ids[] = $user instanceof User ? $user->id : $user;
        }

        app(self::class)->queue($ids);
    }

    public static function bumpRates(): void
    {
        app(self::class)->queue([self::RATES]);
    }

    /**
     * Bump the user who owns a row, plus every member of its space when the
     * space is shared. Reads `user_id` and `space_id` off the row.
     */
    public static function bumpOwnerOf(?Model $row): void
    {
        if ($row === null) {
            return;
        }

        self::bumpOwner($row->getAttribute('user_id'), $row->getAttribute('space_id'));
    }

    /**
     * {@see bumpOwnerOf()} for every row of a bulk write.
     *
     * @param  iterable<Model>  $rows
     */
    public static function bumpOwnersOf(iterable $rows): void
    {
        $owners = [];

        foreach ($rows as $row) {
            $owners[$row->getAttribute('user_id').'|'.$row->getAttribute('space_id')] = $row;
        }

        foreach ($owners as $row) {
            self::bumpOwnerOf($row);
        }
    }

    /**
     * Bump a user plus every member of the space when the space is shared.
     */
    public static function bumpOwner(?string $userId, ?string $spaceId): void
    {
        $instance = app(self::class);

        $instance->queue([
            ...($userId === null ? [] : [$userId]),
            ...$instance->sharedSpaceMembers($spaceId),
        ]);
    }

    /**
     * Run a batch (a bank sync, an import, a scheduled sweep) collecting its
     * bumps, then flush them once when it ends, even when it throws: whatever
     * it committed before failing must still invalidate.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function deferBumps(Closure $callback): mixed
    {
        $instance = app(self::class);
        $instance->deferDepth++;

        try {
            return $callback();
        } finally {
            $instance->deferDepth--;
            $instance->scheduleFlush();
        }
    }

    /**
     * The current token for a user id (or {@see RATES}), minting one when the
     * key is missing or was evicted.
     */
    public static function current(string $id): string
    {
        $store = self::store();
        $key = self::KEY_PREFIX.$id;

        $store->add($key, (string) Str::ulid(), null);

        return (string) $store->get($key);
    }

    /**
     * @param  list<string>  $ids
     */
    private function queue(array $ids): void
    {
        foreach ($ids as $id) {
            $this->pending[$id] = true;
        }

        $this->scheduleFlush();
    }

    private function scheduleFlush(): void
    {
        if ($this->deferDepth > 0 || $this->pending === []) {
            return;
        }

        // Runs right away outside a transaction. Inside one, every queued
        // callback fires on commit and the first flushes the whole set; on a
        // rollback none fires, so the token stays where it was.
        DB::afterCommit(fn () => $this->flush());
    }

    private function flush(): void
    {
        $ids = array_keys($this->pending);
        $this->pending = [];

        try {
            $store = self::store();

            foreach ($ids as $id) {
                $store->forever(self::KEY_PREFIX.$id, (string) Str::ulid());
            }
        } catch (Throwable $exception) {
            // A cache outage must never fail the write that triggered it;
            // the entries' TTL bounds how long they can stay stale.
            report($exception);
        }
    }

    /**
     * Every user who shares a non-personal space, memoised per space for the
     * request or job. Personal spaces have no one else to bump.
     *
     * @return list<string>
     */
    private function sharedSpaceMembers(?string $spaceId): array
    {
        if ($spaceId === null) {
            return [];
        }

        return $this->spaceMembers[$spaceId] ??= $this->loadSharedSpaceMembers($spaceId);
    }

    /**
     * @return list<string>
     */
    private function loadSharedSpaceMembers(string $spaceId): array
    {
        $space = Space::query()->whereKey($spaceId)->first(['id', 'owner_id', 'personal']);

        if ($space === null || $space->personal) {
            return [];
        }

        return [$space->owner_id, ...$space->members()->pluck('users.id')->all()];
    }

    private static function store(): Repository
    {
        return Cache::store(config('cache.user_data.store'));
    }
}
