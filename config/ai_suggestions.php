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
    | contain before it is worth suggesting a rule for (filters one-offs); a
    | single-transaction match is never worth a rule.
    | "max_groups_sent" caps how many groups are sent to the model per run,
    | keeping the payload — and the cost — bounded.
    | "group_batch_size" splits those groups into smaller per-request batches:
    | a large single payload makes the model under-enumerate (it silently skips
    | groups), so we send reliable-size chunks and merge the suggestions.
    |
    */

    'min_group_count' => (int) env('AI_SUGGESTIONS_MIN_GROUP_COUNT', 2),

    'max_groups_sent' => (int) env('AI_SUGGESTIONS_MAX_GROUPS', 500),

    'group_batch_size' => (int) env('AI_SUGGESTIONS_GROUP_BATCH_SIZE', 50),

    /*
    | "noise_token_fraction" makes description grouping language-agnostic: a word
    | appearing in more than this fraction of the user's uncategorized
    | transactions is treated as structural noise ("pago", "carte", "zahlung", a
    | city, …) and dropped from the grouping key, so variants of the same
    | merchant collapse into one group regardless of language. If every word in a
    | description is common, the full set is kept as a fallback.
    */

    'noise_token_fraction' => (float) env('AI_SUGGESTIONS_NOISE_FRACTION', 0.02),

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
    | "confidence_floor" is the minimum confidence for a suggestion to be SHOWN
    | at all. "auto_select_confidence" is the higher bar at or above which a
    | shown suggestion is pre-selected for the user; suggestions between the two
    | are shown but left unchecked so the user opts in deliberately.
    |
    */

    'confidence_floor' => (float) env('AI_SUGGESTIONS_CONFIDENCE_FLOOR', 0.3),

    'auto_select_confidence' => (float) env('AI_SUGGESTIONS_AUTO_SELECT', 0.6),

    'overbroad_fraction' => (float) env('AI_SUGGESTIONS_OVERBROAD_FRACTION', 0.4),

    /*
    | "min_match_count" hides a suggestion card unless the rule it represents
    | would match at least this many of the user's uncategorized transactions.
    | Unlike "min_group_count" (which filters raw groups before the model runs),
    | this is applied to the final OR-rule's real match count at display time, so
    | low-impact rules are never shown. Set to 1 to keep every suggestion visible.
    */

    'min_match_count' => (int) env('AI_SUGGESTIONS_MIN_MATCH_COUNT', 10),

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
