<?php

namespace App\Http\Requests\Concerns;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;

trait ResolvesCategoryCashflowDirection
{
    /**
     * Derive the cashflow direction from the category type before validation runs.
     */
    protected function prepareForValidation(): void
    {
        $type = CategoryType::tryFrom((string) $this->input('type'));

        if (in_array($type, [CategoryType::Savings, CategoryType::Investment], true)) {
            $this->merge([
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
            ]);

            return;
        }

        if ($type !== CategoryType::Transfer) {
            $this->merge([
                'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
            ]);

            return;
        }

        $this->merge([
            'cashflow_direction' => $this->input('cashflow_direction', CategoryCashflowDirection::Hidden->value),
        ]);
    }
}
