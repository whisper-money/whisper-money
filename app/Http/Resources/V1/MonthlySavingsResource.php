<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The month in progress (on your own clock) across your running monthly
 * savings goals: `saved` against `target`, a goal whose first month is
 * partial left out of both, the days left, each goal's month as the savings
 * goals list gives it under `monthly.current`, and `last_month`: how many
 * goals, archived ones included, met their target of how many were judged.
 *
 * @property array{month: string, saved: int, target: int, days_left: int, goals: list<array{id: string, name: string, current: array<string, mixed>|null}>, last_month: array{month: string, met: int, total: int}|null} $resource
 */
class MonthlySavingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return ['currency' => $request->user()->currency_code ?? 'USD', ...$this->resource];
    }
}
