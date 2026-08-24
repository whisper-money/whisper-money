<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Services\TransactionSplitter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape and ownership only. Whether the parts add up, and move money the same
 * way as the original, is enforced by {@see TransactionSplitter} so that every
 * caller gets the same answer.
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
            'splits.*.category_id' => ['nullable', $this->userOwned('categories')],
            'splits.*.label_ids' => ['nullable', 'array'],
            'splits.*.label_ids.*' => ['required', 'string', 'uuid', $this->userOwned('labels')],
        ];
    }
}
