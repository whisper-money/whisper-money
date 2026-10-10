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
     * @param  string  $presence  'required' when creating, 'sometimes' when editing
     * @return array<string, array<mixed>>
     */
    protected function monthlyTargetRules(string $presence): array
    {
        return [
            'monthly_target_type' => [$presence, Rule::enum(MonthlyTargetType::class)],
            'monthly_target_amount' => ['nullable', 'required_if:monthly_target_type,'.MonthlyTargetType::Amount->value, 'integer', 'min:1'],
            // Two decimals, as stored. A share above 100% would ask for more than
            // came in.
            'monthly_target_rate' => ['nullable', 'required_if:monthly_target_type,'.MonthlyTargetType::IncomeRate->value, 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'notify_on_month_end_reminder' => ['sometimes', 'boolean'],
        ];
    }
}
