<?php

namespace App\Http\Requests\Concerns;

use App\Enums\MonthlyTargetType;
use Illuminate\Validation\Rule;

/**
 * The target of a monthly savings goal, shared by the create and edit forms:
 * a fixed amount, or a share of the user's usual income.
 */
trait ValidatesMonthlySavingsTarget
{
    /**
     * @param  bool  $creating  a new goal needs a target; an edit may leave it alone
     * @return array<string, array<mixed>>
     */
    protected function monthlyTargetRules(bool $creating): array
    {
        return [
            // On an edit it is still required alongside either value, so an
            // amount sent without saying what kind of target it is fails
            // instead of being quietly dropped.
            'monthly_target_type' => [$creating ? 'required' : 'required_with:monthly_target_amount,monthly_target_rate', Rule::enum(MonthlyTargetType::class)],
            'monthly_target_amount' => ['nullable', 'required_if:monthly_target_type,'.MonthlyTargetType::Amount->value, 'integer', 'min:1'],
            // Two decimals, as stored. A share above 100% would ask for more than
            // came in.
            'monthly_target_rate' => ['nullable', 'required_if:monthly_target_type,'.MonthlyTargetType::IncomeRate->value, 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'notify_on_month_end_reminder' => ['sometimes', 'boolean'],
        ];
    }
}
