<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The month in progress across your running monthly savings goals, and how
 * every monthly goal did last month.
 *
 * @property array{month: string, saved: int, target: int, days_left: int, goals: list<array{id: string, name: string, current: array<string, mixed>|null}>, last_month: array{month: string, met: int, total: int}|null} $resource
 */
#[SchemaName('MonthlySavings')]
class MonthlySavingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            /**
             * The currency of every amount: your account's currency, as an ISO 4217 code.
             *
             * @example EUR
             */
            'currency' => $user->currency_code ?? 'USD',
            /**
             * The month in progress on your own clock, as `YYYY-MM`.
             *
             * @example 2026-10
             */
            'month' => $this->resource['month'],
            /**
             * What the running goals saved so far this month. A goal whose first month is partial (created in the month's last 5 days) is left out of `saved` and `target`.
             *
             * @var int
             *
             * @example 25000
             */
            'saved' => $this->resource['saved'],
            /**
             * What the running goals aim to save this month, each against its own target.
             *
             * @var int
             *
             * @example 30000
             */
            'target' => $this->resource['target'],
            /**
             * The days left in the month, today included.
             *
             * @var int
             *
             * @example 17
             */
            'days_left' => $this->resource['days_left'],
            /**
             * Every monthly goal that is not archived, oldest first, with its month in progress as `GET /api/v1/savings-goals` gives it under `monthly.current` (`null` when the month has not opened for it yet). Goals of every space you are in count, but only yours.
             *
             * @var list<array{id: string, name: string, current: array{month: string, target: int, saved: int, difference: int, status: string, income_base: ?int, remaining: int, days_left: int}|null}>
             *
             * @example [{"id": "0199a3fb-2c3d-7e4f-8a5b-6c7d8e9f0a12", "name": "Emergency fund", "current": {"month": "2026-10", "target": 30000, "saved": 25000, "difference": -5000, "status": "in_progress", "income_base": null, "remaining": 5000, "days_left": 17}}]
             */
            'goals' => $this->resource['goals'],
            /**
             * How your monthly goals did last month, archived ones included: how many met their target of how many were judged. `null` when no goal was judged that month.
             *
             * @var array{month: string, met: int, total: int}|null
             *
             * @example {"month": "2026-09", "met": 1, "total": 2}
             */
            'last_month' => $this->resource['last_month'],
        ];
    }
}
