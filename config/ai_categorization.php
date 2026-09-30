<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider & model
    |--------------------------------------------------------------------------
    |
    | Provider and model for transaction categorization, both env-overridable.
    | The provider defaults to Gemini but accepts any laravel/ai provider (a
    | valid Laravel\Ai\Enums\Lab case — an unknown value fails fast). Cost is
    | negligible at any tier, so the model is chosen for accuracy. See the
    | README "AI Provider" section for the shared options (e.g. local Ollama).
    |
    */

    'provider' => env('AI_CATEGORIZATION_PROVIDER', env('AI_PROVIDER', 'gemini')),

    'model' => env('AI_CATEGORIZATION_MODEL', 'gemini-flash-latest'),

    /*
    |--------------------------------------------------------------------------
    | Jev share
    |--------------------------------------------------------------------------
    |
    | Fraction (0-1) of transactions sent to TypeSafe AI's Jev instead of the
    | provider above, drawn at random per transaction: 0 keeps everything on
    | the default provider, 0.5 sends half, 1 sends all. It only applies when
    | TYPESAFE_API_KEY is set.
    |
    */

    'jev_ratio' => (float) env('AI_CATEGORIZATION_JEV_RATIO', 0),

    /*
    |--------------------------------------------------------------------------
    | Jev merchant threshold
    |--------------------------------------------------------------------------
    |
    | Jev answers "is this merchant unambiguous?" as a 0-1 score rather than a
    | boolean. A score at or above this bar counts as unambiguous, which is what
    | lets the rule learner generalise the categorization into a rule.
    |
    */

    'jev_unambiguous_threshold' => (float) env('AI_CATEGORIZATION_JEV_UNAMBIGUOUS_THRESHOLD', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | A hard kill switch independent of the per-user Pennant flag. When false,
    | no transaction is ever sent for AI categorization, regardless of rollout.
    |
    */

    'enabled' => (bool) env('AI_CATEGORIZATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Confidence bars
    |--------------------------------------------------------------------------
    |
    | Two thresholds. "label_confidence" is the minimum confidence to auto-apply
    | a category to a single transaction. "rule_confidence" is the higher bar a
    | categorization must clear before it is generalised into an automation rule
    | (a rule mislabels ALL future matches, so it must be more certain). Below
    | "label_confidence" the transaction is left uncategorized.
    |
    */

    'label_confidence' => (float) env('AI_CATEGORIZATION_LABEL_CONFIDENCE', 0.7),

    'rule_confidence' => (float) env('AI_CATEGORIZATION_RULE_CONFIDENCE', 0.85),

    /*
    |--------------------------------------------------------------------------
    | Backfill batching
    |--------------------------------------------------------------------------
    |
    | "group_batch_size" splits aggregated merchant groups into per-request
    | chunks during a backfill run: a large single payload makes the model
    | under-enumerate, so we send reliable-size chunks and merge the results.
    |
    */

    'group_batch_size' => (int) env('AI_CATEGORIZATION_GROUP_BATCH_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Model pricing
    |--------------------------------------------------------------------------
    |
    | USD per million tokens, keyed by model, used by `ai:categorization-eval`
    | to price a run. Gemini bills thinking tokens as output; Jev bills input
    | only. A model missing here is reported with an unknown cost.
    |
    */

    'pricing' => [
        'gemini-2.5-flash-lite' => ['input' => 0.10, 'output' => 0.40],
        'gemini-2.5-flash' => ['input' => 0.30, 'output' => 2.50],
        'gemini-3.5-flash' => ['input' => 1.50, 'output' => 9.00],
        'jev-latest' => ['input' => 0.042, 'output' => 0.0],
        'jev-1.13.0' => ['input' => 0.042, 'output' => 0.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The queue the real-time categorization job runs on. Kept separate from the
    | default queue so a backlog of categorization jobs never delays bank syncs.
    |
    */

    'queue' => env('AI_CATEGORIZATION_QUEUE', 'ai'),

    /*
    |--------------------------------------------------------------------------
    | Transient-failure retry delay
    |--------------------------------------------------------------------------
    |
    | Minutes to wait before retrying a user's still-pending transactions after
    | the AI provider dropped a chunk with a transient failure (overload / rate
    | limit). The delay lets the provider recover before we try again.
    |
    */

    'retry_delay' => (int) env('AI_CATEGORIZATION_RETRY_DELAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Free-plan upsell nudge
    |--------------------------------------------------------------------------
    |
    | Percentage (0-100) of a free user's uncategorized transactions that show
    | the "AI could categorize this" sparkle. Sampled deterministically by
    | transaction id so the same rows always decide the same way. Exposed to the
    | frontend as the `aiCategorizationUpsellRate` Inertia prop.
    |
    */

    'upsell_sample_rate' => (int) env('AI_CATEGORIZATION_UPSELL_SAMPLE_RATE', 40),

];
