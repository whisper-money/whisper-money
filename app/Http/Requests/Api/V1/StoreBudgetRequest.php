<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\User;
use App\Services\BudgetService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    use AddsRefusals, ValidatesUserOwnedResources;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = BudgetService::createRules(BudgetPeriodType::tryFrom((string) $this->input('period_type')));

        return [
            /**
             * The budget's name.
             *
             * @example Groceries
             */
            'name' => $rules['name'],
            /**
             * The limit for each period, in the minor units of your account's currency.
             *
             * @example 50000
             */
            'allocated_amount' => $rules['allocated_amount'],
            /**
             * How often the budget starts over.
             *
             * @example monthly
             */
            'period_type' => $rules['period_type'],
            /**
             * `carry_over` adds what is left at the end of a period to the next one, `reset` starts every period at the limit.
             *
             * @example reset
             */
            'rollover_type' => $rules['rollover_type'],
            /**
             * The day a period starts: the day of the month (1–31) for a monthly budget, the day of the week (0 is Sunday, 6 Saturday) for a weekly or biweekly one. Quarterly and yearly budgets follow the calendar and ignore it.
             *
             * @example 1
             */
            'period_start_day' => $rules['period_start_day'],
            /**
             * `fixed` for spending that repeats at the same amount every period (rent, subscriptions), `variable` (the default) for spending you can adjust. A catch-all budget is always `variable`.
             *
             * @example variable
             */
            'expense_type' => ['sometimes', Rule::enum(BudgetExpenseType::class)],
            /**
             * Track every expense category no other budget tracks, instead of `category_ids` and `label_ids`. An account has at most one catch-all budget.
             *
             * @example false
             */
            'is_catch_all' => $rules['is_catch_all'],
            /**
             * The ids of the categories to track, each one of your own. Tracking a parent also tracks its children. A budget needs at least one category or label, unless it is the catch-all.
             *
             * @example ["0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12"]
             */
            'category_ids' => ['sometimes', 'array'],
            /**
             * @format uuid
             */
            'category_ids.*' => ['string', 'distinct', $this->userOwned('categories')],
            /**
             * The ids of the labels to track, each one of your own.
             *
             * @example ["0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"]
             */
            'label_ids' => ['sometimes', 'array'],
            /**
             * @format uuid
             */
            'label_ids.*' => ['string', 'distinct', $this->userOwned('labels')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category_ids.*.exists' => 'The :attribute is not one of your categories. A budget can only track your own categories.',
            'label_ids.*.exists' => 'The :attribute is not one of your labels. A budget can only track your own labels.',
        ];
    }

    /**
     * The validated fields BudgetService::create() takes as attributes.
     *
     * @return array<string, mixed>
     */
    public function budgetAttributes(): array
    {
        return [
            ...$this->safe()->only(['name', 'period_type', 'rollover_type', 'expense_type']),
            'period_start_day' => $this->validated('period_start_day'),
            'is_catch_all' => $this->boolean('is_catch_all'),
        ];
    }

    /**
     * @return list<string>
     */
    public function categoryIds(): array
    {
        return array_values($this->validated('category_ids', []));
    }

    /**
     * @return list<string>
     */
    public function labelIds(): array
    {
        return array_values($this->validated('label_ids', []));
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var User $user */
            $user = $this->user();

            $this->addRefusals($validator, [
                ...BudgetService::trackingRefusals($user, $this->boolean('is_catch_all'), (array) $this->input('category_ids', []), (array) $this->input('label_ids', [])),
                ...$this->unknownFieldRefusals(),
            ]);
        }];
    }
}
