<?php

namespace App\Http\Resources\V1;

use App\Models\Account;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How much of one credit card is in use, and how that built up day by day.
 *
 * @property array{account: Account, usage: array{limit: int|null, used: int, available: int|null, period_from: string, period_to: string, daily: list<array{date: string, used: int}>}} $resource
 */
#[SchemaName('CreditCardUsage')]
class CreditCardUsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->resource['account'];
        $usage = $this->resource['usage'];

        return [
            /**
             * The credit card's account id.
             *
             * @format uuid
             *
             * @example 0199a3f3-7d2e-7c5f-8b9a-3e6d2c4b5a71
             */
            'account_id' => $account->id,
            /**
             * The account's name.
             *
             * @example Visa Gold
             */
            'name' => $account->name,
            /**
             * The currency of the card's amounts: the account's own currency, as an ISO 4217 code.
             *
             * @example EUR
             */
            'currency' => $account->currency_code,
            /**
             * The card's credit limit in minor units, or `null` while none is set.
             *
             * @var int|null
             *
             * @example 300000
             */
            'limit' => $usage['limit'],
            /**
             * What is still to be charged between `period_from` and today, less the payments made on those statements, never below 0.
             *
             * @var int
             *
             * @example 124000
             */
            'used' => $usage['used'],
            /**
             * `limit` minus `used`, negative over the limit; `null` while no limit is set.
             *
             * @var int|null
             *
             * @example 176000
             */
            'available' => $usage['available'],
            /**
             * The first day `used` covers: the start of the statement paid next, or of the calendar month while the card has no statement dates.
             *
             * @format date
             *
             * @example 2026-10-06
             */
            'period_from' => $usage['period_from'],
            /**
             * The last day of the window: the open statement's closing date, or the end of the calendar month.
             *
             * @format date
             *
             * @example 2026-11-05
             */
            'period_to' => $usage['period_to'],
            /**
             * `used` as it stood at the end of each day from `period_from` through today, oldest first: the line the app draws on the card.
             *
             * @var list<array{date: string, used: int}>
             *
             * @example [{"date": "2026-10-06", "used": 0}, {"date": "2026-10-07", "used": 4500}]
             */
            'daily' => $usage['daily'],
        ];
    }
}
