<?php

namespace App\Http\Requests;

use App\Enums\TransactionSource;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Transaction;
use Illuminate\Contracts\Validation\Validator;
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
            // Split lines. An empty array collapses a split back to a single
            // category; a non-empty one (min 2, checked in withValidator) makes
            // the lines the source of truth. Absent leaves the split untouched.
            'splits' => ['sometimes', 'array'],
            'splits.*.category_id' => ['nullable', $this->userOwned('categories')],
            'splits.*.amount' => ['required', 'integer'],
            'splits.*.label_ids' => ['nullable', 'array'],
            'splits.*.label_ids.*' => ['required', 'string', 'uuid', $this->userOwned('labels')],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('splits')) {
                return;
            }

            $lines = $this->input('splits');

            // An empty array is a valid "unsplit" instruction.
            if (! is_array($lines) || $lines === []) {
                return;
            }

            if (count($lines) < 2) {
                $validator->errors()->add('splits', __('A split needs at least two lines.'));

                return;
            }

            $total = $this->effectiveAmount();

            if ($total === 0) {
                $validator->errors()->add('splits', __("You can't split a transaction with no amount."));

                return;
            }

            $sum = 0;

            foreach ($lines as $index => $line) {
                $amount = (int) ($line['amount'] ?? 0);
                $sum += $amount;

                if ($amount === 0) {
                    $validator->errors()->add("splits.{$index}.amount", __('Each split line must be a non-zero amount.'));
                } elseif (($amount > 0) !== ($total > 0)) {
                    $validator->errors()->add("splits.{$index}.amount", __('Split lines must have the same sign as the transaction.'));
                }
            }

            if ($sum !== $total) {
                $validator->errors()->add('splits', __('The split lines must add up to the transaction amount.'));
            }
        });
    }

    /**
     * The amount the split lines must reconcile against: the newly submitted
     * amount when a manual transaction edits it in the same request, otherwise
     * the transaction's stored amount (imported amounts are locked).
     */
    private function effectiveAmount(): int
    {
        $transaction = $this->route('transaction');

        if ($transaction instanceof Transaction
            && $transaction->source === TransactionSource::ManuallyCreated
            && $this->filled('amount')) {
            return (int) $this->input('amount');
        }

        return $transaction instanceof Transaction ? $transaction->amount : 0;
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category does not exist.',
            'description_iv.size' => 'The description IV must be exactly 16 characters.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
            'label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
            'splits.*.label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
        ];
    }
}
