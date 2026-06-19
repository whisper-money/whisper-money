<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model
    |--------------------------------------------------------------------------
    |
    | The Gemini model used to categorize transactions. Cost is negligible at
    | any tier for this task, so the model is chosen for accuracy, not price.
    | Kept env-overridable so it can be swapped without a deploy.
    |
    */

    'model' => env('AI_CATEGORIZATION_MODEL', 'gemini-flash-latest'),

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
