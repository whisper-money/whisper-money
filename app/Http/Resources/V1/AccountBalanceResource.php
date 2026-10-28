<?php

namespace App\Http\Resources\V1;

use App\Models\AccountBalance;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A balance snapshot: what an account held at the end of one day.
 *
 * @mixin AccountBalance
 */
#[SchemaName('AccountBalance')]
class AccountBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The snapshot's id.
             *
             * @format uuid
             *
             * @example 0199a3f6-2b4c-7d5e-9f60-718293a4b5c6
             */
            'id' => $this->id,
            /**
             * The id of the account the snapshot belongs to.
             *
             * @format uuid
             *
             * @example 0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01
             */
            'account_id' => $this->account_id,
            /**
             * The day the balance is for. An account has at most one snapshot per day.
             *
             * @format date
             *
             * @example 2026-01-31
             */
            'balance_date' => $this->balance_date->toDateString(),
            /**
             * The balance, in the account currency's minor units.
             *
             * @example 250000
             */
            'balance' => $this->balance,
            /**
             * How much of the balance is money put in rather than gains, in minor units, or `null`. Set on investment accounts.
             *
             * @example null
             */
            'invested_amount' => $this->invested_amount,
            /**
             * Whether the snapshot was worked out backwards from a bank-connected account's transactions, rather than reported by the bank or recorded by you. A balance recorded through the API is never derived.
             */
            'derived' => $this->derived,
        ];
    }
}
