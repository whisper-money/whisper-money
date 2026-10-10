<?php

namespace App\Http\Requests\Concerns;

/**
 * The fields of the savings goal forms as validation messages name them, so
 * an error reads "the monthly amount" in the user's language rather than
 * "monthly target amount".
 */
trait NamesSavingsGoalAttributes
{
    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('goal name'),
            'kind' => __('goal type'),
            'target_amount' => __('target amount'),
            'initial_amount' => __('amount already saved'),
            'target_date' => __('target date'),
            'monthly_target_type' => __('monthly target type'),
            'monthly_target_amount' => __('monthly amount'),
            'monthly_target_rate' => __('percentage of income'),
            'notify_on_month_end_reminder' => __('month-end reminder'),
            'auto_tag_account_id' => __('savings account'),
        ];
    }
}
