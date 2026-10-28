<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Your expenses in a date range rolled up by category.
 *
 * @property Collection<int, array<string, mixed>> $resource
 */
#[SchemaName('SpendingByCategory')]
class SpendingByCategoryResource extends JsonResource
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
             * The expense categories with spending in the range, each with its subcategories rolled into it. Only the ones whose spending is above zero: a category that refunds took below zero is left out, and so are uncategorized transactions. Not sorted.
             */
            'categories' => CategoryAmountResource::collection($this->resource),
        ];
    }
}
