<?php

namespace App\Http\Requests;

use App\Enums\TransactionSource;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'uuid'],
            'account_id' => ['required', $this->userOwned('accounts')],
            'category_id' => ['nullable', $this->userOwned('categories')],
            'description' => ['required', 'string'],
            'description_iv' => ['nullable', 'string', 'size:16'],
            'transaction_date' => ['required', 'date'],
            'amount' => ['required', 'integer'],
            'currency_code' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
            'creditor_name' => ['nullable', 'string', 'max:255'],
            'debtor_name' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::enum(TransactionSource::class)],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['string', 'uuid', $this->userOwned('labels')],
        ];
    }

    public function messages(): array
    {
        return [
            'account_id.required' => 'The account is required.',
            'account_id.exists' => 'The selected account does not exist or does not belong to you.',
            'category_id.exists' => 'The selected category does not exist or does not belong to you.',
            'label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
            'description.required' => 'The description is required.',
            'description_iv.size' => 'The description IV must be exactly 16 characters.',
            'transaction_date.required' => 'The transaction date is required.',
            'transaction_date.date' => 'The transaction date must be a valid date.',
            'amount.required' => 'The amount is required.',
            'amount.integer' => 'The amount must be an integer.',
            'currency_code.required' => 'The currency code is required.',
            'currency_code.size' => 'The currency code must be exactly 3 characters.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
            'source.required' => 'The transaction source is required.',
        ];
    }
}
