<?php

namespace App\Http\Requests\Concerns;

use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;

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

    /**
     * The bounds of the amount fields in the money the user types, "0,01 €"
     * rather than the minor units the fields carry.
     *
     * @return array<string, string>
     */
    protected function amountBoundMessages(): array
    {
        /** @var User $user */
        $user = $this->user();
        $currency = $user->currency_code ?? 'USD';
        $locale = $user->formatLocale();
        $messages = [];

        foreach (['target_amount', 'monthly_target_amount'] as $field) {
            $messages["{$field}.min"] = __('The :attribute must be at least :amount.', ['amount' => Money::formatIn(1, $currency, $locale)]);
            $messages["{$field}.max"] = __('The :attribute can\'t be more than :amount.', ['amount' => Money::formatIn(SavingsGoal::MAX_AMOUNT, $currency, $locale)]);
        }

        return $messages;
    }
}
