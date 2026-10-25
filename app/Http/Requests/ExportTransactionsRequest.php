<?php

namespace App\Http\Requests;

/**
 * The transactions page's filters and sort, plus the ids of the rows the list
 * has loaded when the user exports only those.
 */
class ExportTransactionsRequest extends IndexTransactionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'ids' => ['nullable', 'array', 'max:10000'],
            'ids.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @return list<string>|null
     */
    public function ids(): ?array
    {
        return $this->validated('ids');
    }
}
