<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Your net worth at the end of a date range against the previous period, and
 * every account's balance over the range.
 *
 * @property array{granularity: string, current: array{current: int, previous: int}, counted_account_ids: list<string>, evolution: array{data: list<array<string, mixed>>, accounts: Collection<string, array<string, mixed>>}} $resource
 */
#[SchemaName('NetWorth')]
class NetWorthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $accounts = collect($this->resource['evolution']['accounts'])->values();
        $accountIds = $accounts->pluck('id')->all();

        return [
            /**
             * The currency of every amount: your account's currency, as an ISO 4217 code. Balances in another currency are converted at each day's exchange rate.
             *
             * @example EUR
             */
            'currency' => $user->currency_code ?? 'USD',
            /**
             * `monthly` or `daily`, as requested.
             *
             * @var 'monthly'|'daily'
             *
             * @example monthly
             */
            'granularity' => $this->resource['granularity'],
            /**
             * Your net worth on `to`: the balances of the accounts with `counts_in_net_worth`, converted to `currency`, a loan's debt counting against it. Credit cards are never part of it, and loans and real estate only when your net worth settings include them.
             *
             * @var int
             *
             * @example 366364
             */
            'net_worth' => $this->resource['current']['current'],
            /**
             * Your net worth at the end of the previous period: the same number of days right before `from`, or the same number of whole months when the range is whole months.
             *
             * @var int
             *
             * @example 360000
             */
            'previous_net_worth' => $this->resource['current']['previous'],
            /**
             * Every account the evolution covers, archived ones and the ones left out of `net_worth` included: its id, name, type, own currency, and whether it is part of `net_worth`.
             *
             * @var list<array{id: string, name: string, type: string, currency: string, counts_in_net_worth: bool}>
             *
             * @example [{"id": "0199a3f2-8b1c-7d4e-9f5a-1b2c3d4e5f60", "name": "Main checking", "type": "checking", "currency": "EUR", "counts_in_net_worth": true}]
             */
            'accounts' => $accounts->map(fn (array $account): array => [
                'id' => $account['id'],
                'name' => $account['name'],
                'type' => $account['type']->value,
                'currency' => $account['currency_code'],
                'counts_in_net_worth' => in_array($account['id'], $this->resource['counted_account_ids'], true),
            ])->all(),
            /**
             * One point per month the range touches, at the month's last day even when `to` falls before it, or one per day of the range; oldest first, with each account's balance on it.
             */
            'evolution' => NetWorthPointResource::collection(array_map(
                fn (array $point): array => ['point' => $point, 'account_ids' => $accountIds],
                $this->resource['evolution']['data'],
            )),
        ];
    }
}
