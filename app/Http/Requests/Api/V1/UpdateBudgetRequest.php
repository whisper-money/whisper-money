<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BudgetExpenseType;
use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteBudget;
use App\Services\BudgetService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteBudget;

    /**
     * What a budget is built on. Budgets are calculated period by period over
     * their history, so these stay as created (see BudgetService::updateRules()).
     *
     * @var list<string>
     */
    private const array FixedFields = ['period_type', 'period_start_day', 'rollover_type', 'is_catch_all', 'category_ids', 'label_ids'];

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = BudgetService::updateRules();

        return [
            /**
             * The budget's new name.
             *
             * @example Food
             */
            'name' => $rules['name'],
            /**
             * The new limit, in the minor units of your account's currency. It applies to the period in progress and every later one; past periods keep theirs.
             *
             * @example 60000
             */
            'allocated_amount' => $rules['allocated_amount'],
            /**
             * `fixed` or `variable`. A catch-all budget stays `variable` whatever you send.
             *
             * @example fixed
             */
            'expense_type' => ['sometimes', Rule::enum(BudgetExpenseType::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function budgetAttributes(): array
    {
        return $this->safe()->only(['name', 'expense_type']);
    }

    public function allocatedAmount(): ?int
    {
        return $this->has('allocated_amount') ? (int) $this->validated('allocated_amount') : null;
    }

    /**
     * Refuse the fixed fields with the way out, and any other unknown field.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...collect(self::FixedFields)
                ->filter(fn (string $field): bool => $this->has($field))
                ->mapWithKeys(fn (string $field): array => [$field => "A budget's {$field} is fixed once it is created, because its periods are calculated over its history. To change it, delete the budget and create it again."])
                ->all(),
        ])];
    }
}
