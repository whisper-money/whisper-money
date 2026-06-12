<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | The Gemini model used to generate automation-rule suggestions. Kept in
    | config (env-overridable) so the model can be swapped without a deploy.
    | Any Flash-tier model is appropriate for this constrained task.
    |
    */

    'model' => env('AI_SUGGESTIONS_MODEL', 'gemini-flash-latest'),

    /*
    |--------------------------------------------------------------------------
    | Aggregation thresholds
    |--------------------------------------------------------------------------
    |
    | "min_group_count" is the minimum number of transactions a group must
    | contain before it is worth suggesting a rule for (filters one-offs).
    | "max_groups_sent" caps how many groups are sent to the model per run,
    | keeping the payload — and the cost — bounded.
    |
    */

    'min_group_count' => (int) env('AI_SUGGESTIONS_MIN_GROUP_COUNT', 3),

    'max_groups_sent' => (int) env('AI_SUGGESTIONS_MAX_GROUPS', 15),

    /*
    |--------------------------------------------------------------------------
    | Quality guards
    |--------------------------------------------------------------------------
    |
    | "confidence_floor" drops suggestions the model is not confident about.
    | "overbroad_fraction" rejects a match token that would match more than
    | this fraction of the user's uncategorized transactions (a token so
    | broad it would mis-categorise en masse).
    |
    */

    'confidence_floor' => (float) env('AI_SUGGESTIONS_CONFIDENCE_FLOOR', 0.7),

    'overbroad_fraction' => (float) env('AI_SUGGESTIONS_OVERBROAD_FRACTION', 0.4),

    /*
    |--------------------------------------------------------------------------
    | Eligibility & throttle
    |--------------------------------------------------------------------------
    |
    | A run only happens when the user has at least "eligibility_min_transactions"
    | transactions. "throttle_days" is the minimum spacing between successful
    | runs (a fresh run before this window is blocked to avoid extra cost).
    |
    */

    'eligibility_min_transactions' => (int) env('AI_SUGGESTIONS_MIN_TRANSACTIONS', 50),

    'throttle_days' => (int) env('AI_SUGGESTIONS_THROTTLE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Consent version
    |--------------------------------------------------------------------------
    |
    | The current version of the AI consent copy. Bumping this invalidates
    | prior consents so users are re-prompted when the terms change.
    |
    */

    'consent_version' => (string) env('AI_SUGGESTIONS_CONSENT_VERSION', '1'),

];
