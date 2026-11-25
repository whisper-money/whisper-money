<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Services\TransactionSplitter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class MergeTransactionSplitsRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteTransaction;

    /**
     * No body: the part names the split, and the original remembers the
     * category it had.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Refuse unknown fields, and a transaction that is not part of a split.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return $this->refusingUnknownFieldsAnd(fn (): array => [
            'transaction' => TransactionSplitter::mergeError($this->transaction()),
        ]);
    }
}
