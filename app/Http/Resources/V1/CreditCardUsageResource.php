<?php

namespace App\Http\Resources\V1;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How much of one credit card is in use, in the card's own currency: `limit`
 * (null while none is set), `used` (what is still to be charged from
 * `period_from` to today, less the payments made on those statements, never
 * below 0), `available` (`limit` minus `used`), the window and `daily`, the
 * running `used` from `period_from` through today. A name still encrypted on
 * the device reads '', as on {@see AccountResource}.
 *
 * @property array{account: Account, usage: array{limit: int|null, used: int, available: int|null, period_from: string, period_to: string, daily: list<array{date: string, used: int}>}} $resource
 */
class CreditCardUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->resource['account'];

        return [
            'account_id' => $account->id,
            'name' => $account->name_iv === null ? $account->name : '',
            'currency' => $account->currency_code,
            ...$this->resource['usage'],
        ];
    }
}
