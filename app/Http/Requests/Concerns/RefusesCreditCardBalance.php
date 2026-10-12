<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Contracts\Validation\Validator;

/**
 * For the requests that write a balance row on the route's account. A credit
 * card has a limit and what is in use of it instead of a balance, so a balance
 * written for one is refused rather than stored and hidden.
 */
trait RefusesCreditCardBalance
{
    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('account');

            // Someone else's account is the controller's 403 to give, so its
            // type is not revealed by a validation error first.
            if (! $account instanceof Account || $this->user()->cannot('update', $account)) {
                return;
            }

            if (! $account->type->hasBalance()) {
                $validator->errors()->add('balance', AccountType::NO_BALANCE_MESSAGE);
            }
        }];
    }
}
