<?php

namespace App\Http\Resources\V1;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every account's balance on one point of the net worth evolution.
 *
 * @property array{point: array<string, mixed>, account_ids: list<string>} $resource
 */
#[SchemaName('NetWorthPoint')]
class NetWorthPointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $point = $this->resource['point'];

        return [
            /**
             * The month (`2026-10`) or, with `daily` granularity, the day (`2026-10-15`).
             *
             * @var string
             *
             * @example 2026-10
             */
            'period' => $point['month'] ?? $point['date'],
            /**
             * One entry per account in `accounts`, in the same order: `balance` in `currency`, a loan's as the positive amount owed; `original_balance` and `original_currency` the account's own figure when its currency is another one, `null` otherwise; `invested_amount` what was put into an investment or retirement account on a monthly point, `null` for other types, on daily points or when not recorded.
             *
             * @var list<array{account_id: string, balance: int, original_balance: int|null, original_currency: string|null, invested_amount: int|null}>
             *
             * @example [{"account_id": "0199a3f2-8b1c-7d4e-9f5a-1b2c3d4e5f60", "balance": 130000, "original_balance": null, "original_currency": null, "invested_amount": null}]
             */
            'balances' => array_map(fn (string $id): array => [
                'account_id' => $id,
                'balance' => $point[$id],
                'original_balance' => $point["{$id}_original"]['amount'] ?? null,
                'original_currency' => $point["{$id}_original"]['currency_code'] ?? null,
                'invested_amount' => $point["{$id}_invested"] ?? null,
            ], $this->resource['account_ids']),
        ];
    }
}
