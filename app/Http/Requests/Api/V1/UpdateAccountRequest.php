<?php

namespace App\Http\Requests\Api\V1;

use App\Http\ApiV1\ApiV1Exception;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteAccount;
use App\Http\Requests\Api\V1\Concerns\ValidatesAccountWrites;
use App\Models\User;
use App\Services\Accounts\AccountWriteRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
{
    use ResolvesRouteAccount, ValidatesAccountWrites;

    /**
     * Any member of a space reads its accounts, but only an account's owner
     * edits it.
     */
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $this->account()->user_id === $user->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->accountRules();
        $account = $this->accountOrNull();
        $fields = $rules->updateRules($this->requestedType() ?? $account?->type, $account?->creditCardDetail);

        return [
            /**
             * The account's new name. The sync never rewrites it, so a bank-connected account can be renamed too.
             *
             * @example Joint checking
             */
            'name' => $fields['name'],
            /**
             * The new account type. On a bank-connected account only `checking`, `credit_card`, `savings` and `others` are possible: the rest keep no transaction ledger for the sync to write into.
             */
            'type' => $rules->typeRule(required: false)['type'],
            /**
             * The new ISO 4217 currency. Refused on a bank-connected account, whose currency comes from the bank.
             */
            'currency_code' => $fields['currency_code'],
            /**
             * The new bank id, or `null` to clear it. Refused on a bank-connected account, whose bank comes from the connection.
             */
            'bank_id' => $fields['bank_id'],
            /**
             * Your share of the account, 1 to 100. Below 100 only that slice counts in your figures, and the budgets that already counted the account are reweighed.
             *
             * @example 50
             */
            'ownership_percentage' => $fields['ownership_percentage'],
            /**
             * Whether the ownership share applies to the balance too, not only to transactions.
             */
            'ownership_applies_to_balance' => $fields['ownership_applies_to_balance'],
            ...$this->detailFields($fields),
        ];
    }

    /**
     * Refuse changing what the bank sync owns on a connected account.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $errors = $this->accountRules()->syncOwnedFieldErrors($this->account(), $this->all(), $this->requestedType());

            $this->addErrors($validator, $errors);
        }];
    }

    protected function failedAuthorization(): never
    {
        throw new ApiV1Exception(403, 'not_account_owner', AccountWriteRules::notOwnerMessage($this->account()));
    }
}
