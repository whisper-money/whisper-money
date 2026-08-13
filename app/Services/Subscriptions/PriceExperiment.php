<?php

namespace App\Services\Subscriptions;

use App\Models\User;

/**
 * A/B split on the price of the paid plan: `control` keeps the plans.* prices,
 * `high` swaps in the variant tier.
 *
 * The arm is drawn for an **anonymous visitor** on their first page view and kept
 * in a cookie, then copied onto the user row at registration. It has to work that
 * way round: the landing quotes a price before anyone has an account, so assigning
 * at registration would advertise the control price to everyone and then switch
 * half of them to the high one — measuring the annoyance of a price that moved
 * rather than the price itself, and hiding the visitors who would never have
 * signed up at the higher price at all.
 *
 * Anyone without an arm — registered before the experiment, cookies blocked,
 * arrived straight at a deep link — is `legacy` and pays the control price.
 *
 * @api The arm names are the vocabulary the experiment is configured and read
 *      with: the accepted values of PRICE_EXPERIMENT_FORCE_VARIANT, the contents
 *      of users.price_arm, and what a funnel report groups by.
 */
class PriceExperiment
{
    public const COOKIE = 'price_arm';

    public const LEGACY = 'legacy';

    public const CONTROL = 'control';

    public const HIGH = 'high';

    /**
     * Whether new visitors should still be drawn into the split. A forced variant
     * means a winner is being rolled out to everyone, so the split is over even
     * though the start date is still set.
     */
    public static function isRunning(): bool
    {
        // blank(), not === null: an empty PRICE_EXPERIMENT_STARTED_AT in the env
        // reads back as '', and treating that as a start date would launch the
        // experiment — charging the high price — on an ops typo.
        return filled(config('subscriptions.price_experiment.started_at'))
            && self::forcedArm() === null;
    }

    /**
     * A fresh 50/50 draw for a visitor we have not seen before. Random rather than
     * a hash: there is no stable identifier to hash before the user exists.
     */
    public static function draw(): string
    {
        return random_int(0, 1) === 0 ? self::CONTROL : self::HIGH;
    }

    /**
     * A stored or cookie value narrowed to a real arm, or null. Guards the column
     * and the cookie alike, both of which a user can put anything into.
     */
    public static function sanitize(?string $arm): ?string
    {
        return in_array($arm, [self::CONTROL, self::HIGH], true) ? $arm : null;
    }

    /**
     * The arm to price a request with: a signed-in user keeps the arm stored at
     * registration, a guest gets the one drawn into their cookie.
     */
    public static function armFor(?User $user, ?string $cookieArm = null): string
    {
        return self::forcedArm()
            ?? self::sanitize($user !== null ? $user->price_arm : $cookieArm)
            ?? self::LEGACY;
    }

    /**
     * The plans config with the request's arm applied: price, original_price and
     * Stripe lookup key. Feeds both the shared pricing prop and checkout, so what
     * is shown is always what is charged.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function plansFor(?User $user, ?string $cookieArm = null): array
    {
        $plans = (array) config('subscriptions.plans', []);
        $arm = self::armFor($user, $cookieArm);
        $overrides = (array) config("subscriptions.price_experiment.variants.{$arm}", []);

        foreach ($overrides as $planKey => $override) {
            if (! isset($plans[$planKey])) {
                continue;
            }

            $plans[$planKey]['price'] = $override['price'];
            $plans[$planKey]['original_price'] = $override['original_price'] ?? null;
            $plans[$planKey]['stripe_lookup_key'] = $override['lookup'];
        }

        return $plans;
    }

    /**
     * Stripe lookup key to charge for a plan, resolved from the user's stored arm
     * server-side and never from the request, so nobody can pick the cheap price
     * by editing their cookie after signing up.
     */
    public static function lookupKeyFor(User $user, string $planKey): string
    {
        return (string) (self::plansFor($user)[$planKey]['stripe_lookup_key'] ?? '');
    }

    /**
     * The winner pinned via PRICE_EXPERIMENT_FORCE_VARIANT, applied to everyone —
     * visitors and existing users — so a rollout needs no deploy and no backfill.
     */
    private static function forcedArm(): ?string
    {
        return self::sanitize(config('subscriptions.price_experiment.force_variant'));
    }
}
