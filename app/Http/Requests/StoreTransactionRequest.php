<?php

namespace App\Http\Requests;

use App\Enums\TransactionSource;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Rules\AssignableLabel;
use App\Rules\StorableDate;
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
            'transaction_date' => ['required', 'date', new StorableDate],
            'amount' => ['required', 'integer'],
            'currency_code' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string'],
            'creditor_name' => ['nullable', 'string', 'max:255'],
            'debtor_name' => ['nullable', 'string', 'max:255'],
            'source' => ['required', Rule::enum(TransactionSource::class)],
            // The id the file's own app gave the row, so importing it again
            // is caught by the duplicate check.
            'external_transaction_id' => ['nullable', 'string', 'max:255'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['string', 'uuid', $this->userOwned('labels'), ...$this->assignableLabelRules()],
        ];
    }

    /**
     * A CSV import brings in history, so a label its rows carry is assigned
     * even when it has been archived since. Anything else is a new
     * transaction, which cannot get an archived label. `source` is the
     * client's word, so this only keeps the app from offering archived labels:
     * a user can at most tag their own rows, which is acceptable.
     *
     * @return list<AssignableLabel>
     */
    private function assignableLabelRules(): array
    {
        return $this->input('source') === TransactionSource::Imported->value ? [] : [new AssignableLabel];
    }

    public function messages(): array
    {
        return [
            'account_id.required' => 'The account is required.',
            'account_id.exists' => 'The selected account does not exist or does not belong to you.',
            'category_id.exists' => 'The selected category does not exist or does not belong to you.',
            'label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
            'description.required' => 'The description is required.',
            'transaction_date.required' => 'The transaction date is required.',
            'transaction_date.date' => 'The transaction date must be a valid date.',
            'amount.required' => 'The amount is required.',
            'amount.integer' => 'The amount must be an integer.',
            'currency_code.required' => 'The currency code is required.',
            'currency_code.size' => 'The currency code must be exactly 3 characters.',
            'source.required' => 'The transaction source is required.',
        ];
    }
}
