<?php

namespace App\Http\Resources\V1;

use App\Models\Account;
use App\Models\User;
use App\Services\CreditCards\CreditCardStatementService;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * An account: a bookkeeping record of money held somewhere, either kept by
 * hand or connected to a bank that syncs it.
 *
 * @mixin Account
 */
#[SchemaName('Account')]
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        // The day-by-day usage series is for the app's chart, not the API.
        $creditCard = Arr::except(app(CreditCardStatementService::class)->presentFor($this->resource, $user), 'credit_card_usage.daily');

        return [
            /**
             * The account's id.
             *
             * @format uuid
             *
             * @example 0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01
             */
            'id' => $this->id,
            /**
             * The id of the space the account is in.
             *
             * @format uuid
             *
             * @example 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
             */
            'space_id' => $this->space_id,
            /**
             * The account's name.
             *
             * @example Main checking
             */
            'name' => $this->name,
            /**
             * The account type. `checking`, `credit_card`, `savings` and `others` keep a transaction ledger; `investment`, `retirement`, `loan` and `real_estate` track a balance only.
             *
             * @example checking
             */
            'type' => $this->type->value,
            /**
             * The ISO 4217 currency the account is held in, which its amounts are in.
             *
             * @example EUR
             */
            'currency' => $this->currency_code,
            /**
             * The bank the account is held at, if one is set: its id and name.
             *
             * @example {"id": "0199a3f1-5b2c-7e8d-9f0a-1b2c3d4e5f60", "name": "BBVA"}
             */
            'bank' => $this->bank === null ? null : ['id' => $this->bank->id, 'name' => $this->bank->name],
            /**
             * Whether the account is connected to a bank. A connected account's balances, currency and bank come from the sync: only its name, type and ownership can be changed, and it takes no manual balance.
             *
             * @example false
             */
            'is_connected' => $this->isConnected(),
            /**
             * The owner's share of the account, 1 to 100. Below 100 only that slice counts in their figures.
             *
             * @example 100
             */
            'ownership_percentage' => $this->ownership_percentage,
            /**
             * Whether the ownership share applies to the balance too, not only to transactions.
             */
            'ownership_applies_to_balance' => $this->ownership_applies_to_balance,
            /**
             * `credit_card` only, otherwise `null`: the card's limit and statement dates, in minor units and ISO dates. Later cycles repeat from the dates at `statement_frequency`. `null` while none is set.
             *
             * @var array{statement_closing_date: string|null, payment_due_date: string|null, statement_frequency: 'monthly'|'weekly', credit_limit: int|null}|null
             *
             * @example null
             */
            'credit_card_detail' => $creditCard['credit_card_detail'] ?? null,
            /**
             * `credit_card` only, otherwise `null`: an estimate of the card's next charge, computed from its statement dates and its transactions. It is not a transaction, and `next_payment.is_final` is false while the cycle is still open. `null` while the card has no statement dates.
             *
             * @var array{
             *     next_payment: array{period_from: string, closing_date: string, due_date: string, amount: int, is_final: bool, paid: int},
             *     current_cycle: array{period_from: string, closing_date: string, due_date: string, amount: int},
             *     payments: list<array{transaction_id: string, date: string, amount: int, period_from: string, closing_date: string, due_date: string}>,
             *     previous_statement: array{period_from: string, closing_date: string, due_date: string, purchases: int, paid: int, pending: int, has_recorded_purchases: bool},
             * }|null
             *
             * @example null
             */
            'credit_card_statement' => $creditCard['credit_card_statement'] ?? null,
            /**
             * `credit_card` only, otherwise `null`: how much of the card is in use. `used` is what is still to be charged between `period_from` and today, less the payments made on those statements, never below 0. `available` is `limit` minus it, negative over the limit; both `limit` and `available` are `null` while no credit limit is set.
             *
             * @var array{limit: int|null, used: int, available: int|null, period_from: string, period_to: string}|null
             *
             * @example null
             */
            'credit_card_usage' => $creditCard['credit_card_usage'] ?? null,
            /**
             * When the account was archived in the app, or `null`. An archived account keeps its history and still answers here.
             *
             * @format date-time
             *
             * @example null
             */
            'archived_at' => $this->archived_at?->toIso8601String(),
            /**
             * When the account was created.
             *
             * @format date-time
             *
             * @example 2026-01-15T09:30:00+00:00
             */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
