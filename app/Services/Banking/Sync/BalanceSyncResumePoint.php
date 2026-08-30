<?php

namespace App\Services\Banking\Sync;

use App\Models\Account;
use Carbon\Carbon;

/**
 * Where a balance sync picks up from.
 *
 * A first sync backfills all the history the provider offers; every later sync
 * resumes at the last balance already recorded, so the same history is not
 * re-walked on every run.
 */
class BalanceSyncResumePoint
{
    /**
     * The last balance date already recorded, or null when there is nothing to
     * resume from: a first sync, or an account with no balances yet.
     *
     * Callers filter provider rows with `$date < $lastSyncedDate`, so the
     * boundary day itself is re-synced rather than skipped - it may still have
     * been incomplete when it was first stored.
     */
    public static function lastSyncedDate(Account $account, bool $isFirstSync): ?string
    {
        if ($isFirstSync) {
            return null;
        }

        $lastSyncedDate = $account->balances()->max('balance_date');

        return $lastSyncedDate ? (string) $lastSyncedDate : null;
    }

    /**
     * The first date a windowed history fetch should ask the provider for: the
     * day after the last recorded balance, or $fallback when there is none.
     *
     * Unlike lastSyncedDate(), this moves past the boundary day: a fetch window
     * is asked of the provider up front, so re-requesting a day already stored
     * would only cost an API call.
     */
    public static function startDate(Account $account, bool $isFirstSync, Carbon $fallback): Carbon
    {
        $lastSyncedDate = self::lastSyncedDate($account, $isFirstSync);

        return $lastSyncedDate ? Carbon::parse($lastSyncedDate)->addDay() : $fallback;
    }
}
