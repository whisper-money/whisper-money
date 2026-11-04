<?php

namespace App\Features;

use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * A/B test on the copy of the onboarding bank and broker gate: whether framing
 * the plan as a free trial ("Connect your bank free for 14 days", the price in
 * the fine print) recovers the bank connections lost when the price went up.
 *
 * Three arms, a third each:
 * - `control`: the gate as it was.
 * - `trial_yearly`: the free-trial framing, yearly plan preselected.
 * - `trial_monthly`: the free-trial framing, monthly plan preselected.
 *
 * Same product, same trial, same checkout in every arm; only the framing and
 * the preselected plan change.
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
 * `false` too — everybody sees the control gate and no arm is reported.
 * `feature:enable … all` writes `true` back, which {@see self::armFor()} reads
 * as "back in the experiment" and hashes again, so every user returns to the
 * arm they had.
 *
 * @api
 */
class OnboardingGateCopy
{
    public const CONTROL = 'control';

    public const TRIAL_YEARLY = 'trial_yearly';

    public const TRIAL_MONTHLY = 'trial_monthly';

    /**
     * Keeps this experiment's split independent of any other one hashed off
     * the same user ids.
     */
    private const SALT = 'onboarding-gate-copy:';

    /** @var list<string> */
    public const ARMS = [self::CONTROL, self::TRIAL_YEARLY, self::TRIAL_MONTHLY];

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

        return in_array($value, self::ARMS, true) ? $value : null;
    }

    private static function hashedArm(User $user): string
    {
        return self::ARMS[crc32(self::SALT.$user->id) % count(self::ARMS)];
    }
}
