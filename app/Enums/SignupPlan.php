<?php

namespace App\Enums;

/**
 * The pricing card a visitor started their registration from, carried on the
 * register link as ?plan= and frozen onto the user row at sign-up.
 *
 * It only says which card was clicked, never what the user is entitled to:
 * entitlements come from the subscription. Onboarding reads it to stop offering
 * paid features to someone who picked the free card, and to drop the "you'll
 * choose a plan at the end" warnings for someone who already picked a paid one.
 *
 * Null — every other entry point, an unknown value, a hand-typed /register — is
 * the untouched default flow.
 */
enum SignupPlan: string
{
    case Free = 'free';

    case Paid = 'paid';
}
