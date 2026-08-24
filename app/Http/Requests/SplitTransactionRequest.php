<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;

class SplitTransactionRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'splits' => ['required', 'array', 'min:2', 'max:20'],
            'splits.*.amount' => ['required', 'integer', 'not_in:0'],
            'splits.*.category_id' => ['nullable', $this->userOwned('categories')],
            'splits.*.label_ids' => ['nullable', 'array'],
            'splits.*.label_ids.*' => ['required', 'string', 'uuid', $this->userOwned('labels')],
        ];
    }

    /**
     * The two invariants that keep a split honest: the parts add up to the
     * original, and every part moves money the same way it did. The dialog
     * enforces both before it lets you save; this is what makes them true for
     * every other caller.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $transaction = $this->route('transaction');

            if (! $transaction instanceof Transaction || $validator->errors()->isNotEmpty()) {
                return;
            }

            $amounts = array_map(
                static fn ($split): int => (int) ($split['amount'] ?? 0),
                (array) $this->input('splits', []),
            );

            if (array_sum($amounts) !== $transaction->amount) {
                $validator->errors()->add('splits', 'The splits must add up to the original amount.');
            }

            $movesTheSameWay = static fn (int $amount): bool => ($amount > 0) === ($transaction->amount > 0);

            if (count(array_filter($amounts, $movesTheSameWay)) !== count($amounts)) {
                $validator->errors()->add('splits', 'Every split must move money the same way as the original.');
            }
        });
    }
}
