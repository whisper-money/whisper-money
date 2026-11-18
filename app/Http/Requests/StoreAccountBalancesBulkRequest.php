<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RefusesCreditCardBalance;
use App\Rules\StorableDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Many daily balances for one account in a single request, for an import that
 * carries a balance per day (a broker export can hold years of them).
 */
class StoreAccountBalancesBulkRequest extends FormRequest
{
    use RefusesCreditCardBalance;

    /** The most balances one request may carry. */
    public const int MAX_BALANCES = 5000;

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
            'balances' => ['required', 'array', 'list', 'max:'.self::MAX_BALANCES],
            'balances.*.balance_date' => ['required', 'date', new StorableDate],
            'balances.*.balance' => ['required', 'integer'],
            'balances.*.invested_amount' => ['nullable', 'integer'],
        ];
    }
}
