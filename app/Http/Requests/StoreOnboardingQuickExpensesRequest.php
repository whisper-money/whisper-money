<?php

namespace App\Http\Requests;

use App\Enums\QuickExpense;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnboardingQuickExpensesRequest extends FormRequest
{
    /** Keeps a hand-rolled request from writing something absurd. */
    private const MAX_AMOUNT = 100_000_000_000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid'],
            'expenses' => ['required', 'array', 'min:1', 'max:'.count(QuickExpense::cases())],
            'expenses.*.kind' => ['required', 'distinct', Rule::enum(QuickExpense::class)],
            // In the minor units of the account's currency.
            'expenses.*.amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
        ];
    }

    /**
     * The amounts keyed by expense kind.
     *
     * @return array<string, int>
     */
    public function amounts(): array
    {
        return collect($this->validated('expenses'))
            ->mapWithKeys(fn (array $expense): array => [$expense['kind'] => (int) $expense['amount']])
            ->all();
    }
}
