<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Transaction;
use App\Services\TransactionSplitter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Exists;

/**
 * Shape and ownership only. Whether the parts add up, and move money the same
 * way as the original, is enforced by {@see TransactionSplitter} so that every
 * caller gets the same answer.
 *
 * A part's category and labels are the user's own and in the transaction's
 * own space, as the app's split always required of what it files a
 * transaction under: never another space's, never a deleted one.
 */
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
            'splits' => ['required', 'array', 'min:2', 'max:'.TransactionSplitter::MAX_PARTS],
            'splits.*.amount' => ['required', 'integer'],
            'splits.*.category_id' => ['nullable', $this->ownedInTransactionSpace('categories')],
            'splits.*.label_ids' => ['nullable', 'array'],
            'splits.*.label_ids.*' => ['required', 'string', 'uuid', $this->ownedInTransactionSpace('labels')],
        ];
    }

    /**
     * {@see self::userOwned()}, live and in the space of the transaction split.
     */
    private function ownedInTransactionSpace(string $table): Exists
    {
        /** @var Transaction $transaction */
        $transaction = $this->route('transaction');

        return $this->userOwned($table)->where('space_id', $transaction->space_id)->whereNull('deleted_at');
    }
}
