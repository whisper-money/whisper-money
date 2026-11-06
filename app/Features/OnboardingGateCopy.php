<?php

namespace App\Features;

use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * A/B test on the copy of the onboarding bank and broker gate: whether framing
 * the plan as a free trial ("Connect your bank free for 14 days", the price in
 * the fine print) recovers the bank connections lost when the price went up,
 * and which plan that trial should preselect.
 *
 * Two arms, half each:
 * - `trial_yearly`: the free-trial framing, yearly plan preselected.
 * - `trial_monthly`: the free-trial framing, monthly plan preselected.
 *
 * Same product, same trial, same checkout in both; only the preselected plan
 * changes.
 *
 * It started on 2026-11-04 with a third arm, `control` (the gate as it was).
 * Only ~50 users a month reach the bank gate, too few to read three arms, and
 * control's preselected plan followed `SUBSCRIPTIONS_DEFAULT_PLAN`, which moves
 * on 2026-12-02, so it was dropped. Since then:
 * - New users are drawn from a hash with a new salt, so the two-arm split is
 *   independent of the three-arm one.
 * - Pennant only calls {@see self::resolve()} when nothing is stored, so a
 *   user already stored in a trial arm keeps it.
 * - A user stored as `control` (almost all past onboarding by now) is read
 *   as out of the experiment: they see the gate as it was, the same thing
 *   control showed, and no arm is reported, so the two-arm read stays clean.
 *
 * The arm is a salted hash of the user id, so it is stable without having to
 * be stored, and Pennant stores it anyway on the first read. That read happens
 * when the onboarding page loads, not when the gate is shown, so the `features`
 * table holds who was *assigned*, not who was *exposed*: exposure is the
 * `onboarding_gate_viewed` event in PostHog. For trial→paid, join it against
 * subscriptions whose `upsell_source` is `onboarding_bank`/`onboarding_broker` —
 * only someone who saw the gate can have checked out from it.
 *
 * Off switch, no deploy: `php artisan feature:disable OnboardingGateCopy all`.
 * That writes `false` over every stored value, the global (null-scope) one
 * included, and a user resolved afterwards reads that global value and gets
 * `false` too — everybody sees the gate as it was and no arm is reported.
 * `feature:enable … all` writes `true` back, which {@see self::armFor()} reads
 * as "back in the experiment" and hashes again, so every user drawn by the
 * two-arm hash returns to the arm they had. Anyone stored before the control
 * arm was dropped is drawn again from the new hash instead — a former control
 * user lands in a trial arm, and about half the old trial users switch arm —
 * so a read across a kill-switch round trip should drop those users.
 *
 * @api
 */
class OnboardingGateCopy
{
    public const TRIAL_YEARLY = 'trial_yearly';

    public const TRIAL_MONTHLY = 'trial_monthly';

    /**
     * Keeps this experiment's split independent of any other one hashed off
     * the same user ids.
     */
    private const SALT = 'onboarding-gate-copy-two-arms:';

    /** @var list<string> */
    public const ARMS = [self::TRIAL_YEARLY, self::TRIAL_MONTHLY];

    /**
     * The null scope is the experiment's global switch: it is running until
     * `feature:disable … all` stores `false` against it.
     */
    public function resolve(?User $user): string|bool
    {
        if ($user === null) {
            return true;
        }

        if (! Feature::for(null)->active(self::class)) {
            return false;
        }

        return self::hashedArm($user);
    }

    /**
     * The arm a user's gate is drawn in, or null when the experiment is off
     * for them.
     */
    public static function armFor(User $user): ?string
    {
        // `true` is what `feature:enable … all` writes over every stored arm:
        // back in the experiment, in the arm the hash always gave.
        $value = Feature::for($user)->value(self::class);

        if ($value === true) {
            return self::hashedArm($user);
        }

        // A stored `control` from the three-arm run is not in ARMS, so it
        // reads as out of the experiment, like any other unknown value.
        return in_array($value, self::ARMS, true) ? $value : null;
    }

    private static function hashedArm(User $user): string
    {
        return self::ARMS[crc32(self::SALT.$user->id) % count(self::ARMS)];
    }
}
