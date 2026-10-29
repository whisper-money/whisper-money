<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Http\Requests\Api\V1\Concerns\ValidatesTransactionWrites;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
{
    use ResolvesRouteTransaction, ValidatesTransactionWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->transactionFields(TransactionWriteRules::updateRules());
    }

    /**
     * Refuse unknown fields, and the core fields a transaction locks: all of
     * them on a split part, all but the date on one that came from a bank or
     * import.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $sent = $this->sentFields();
            $lockedFieldError = TransactionWriteRules::lockedCoreFieldError($this->transaction(), $sent);

            $this->addRefusals($validator, [
                ...$this->unknownFieldRefusals(),
                ...$lockedFieldError === null ? [] : array_fill_keys(TransactionWriteRules::lockedCoreFields($this->transaction(), $sent), $lockedFieldError),
            ]);
        }];
    }
}
