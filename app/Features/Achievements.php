<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the medals: the daily sweep, the progress screen, the dashboard strip
 * and the rows they ring the bell with. Off unless `ACHIEVEMENTS_ENABLED` says
 * otherwise, so the rollout is a variable in Coolify rather than a deploy.
 *
 * Pennant persists whatever `resolve()` returns: the first check for a scope
 * writes a row to the `features` table and this method is never consulted for
 * that scope again. Flipping the env var therefore only reaches users with no
 * row yet — everyone already resolved to `false` stays off. Whoever flips it
 * must also run one of:
 *
 * - `php artisan feature:enable Achievements all` — updates the stored rows in
 *   place.
 * - `php artisan pennant:purge "App\\Features\\Achievements"` — drops them so
 *   every scope falls through to `resolve()` again.
 *
 * The production entrypoint runs `php artisan config:cache`, so the var is read
 * at container boot: the change lands on the restart, not live.
 *
 * @api
 */
class Achievements
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return (bool) config('achievements.enabled');
    }
}
