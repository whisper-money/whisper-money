<?php

namespace App\Http\Requests;

use App\Enums\TransactionSource;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'category_id' => ['nullable', $this->userOwned('categories')],
            'description' => ['sometimes', 'string'],
            'description_iv' => ['nullable', 'string', 'size:16'],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
            'creditor_name' => ['nullable', 'string', 'max:255'],
            'debtor_name' => ['nullable', 'string', 'max:255'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['required', 'string', 'uuid', $this->userOwned('labels')],
        ];

        // Manually created transactions can edit every field after creation.
        // Imported ones keep amount, date, account and currency locked to the
        // source data, so those keys are only validated (and thus persisted)
        // for manual transactions.
        $transaction = $this->route('transaction');
        if ($transaction instanceof Transaction && $transaction->source === TransactionSource::ManuallyCreated) {
            $rules['account_id'] = ['sometimes', $this->userOwned('accounts')];
            $rules['transaction_date'] = ['sometimes', 'date'];
            $rules['amount'] = ['sometimes', 'integer'];
            $rules['currency_code'] = ['sometimes', 'string', 'size:3'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category does not exist.',
            'description_iv.size' => 'The description IV must be exactly 16 characters.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
            'label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
        ];
    }
}
