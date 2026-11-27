<?php

namespace App\Services\Forecast;

use App\Enums\ForecastSuggestionKind;
use App\Models\Account;
use App\Models\ForecastDismissal;
use App\Models\ForecastRecurringDrop;
use App\Models\User;
use App\Services\Forecast\Data\RecurringCharge;

/**
 * The "Recurring transactions" card of one space: each recurring charge
 * (see RecurringCharges) with its account, whether the user is dropping it
 * (from which month, and how much that took off the forecast, which is
 * less than the charge when its category had less left) or keeping it,
 * and what they all cost a month, per currency. Dropped and kept charges stay listed.
 */
class RecurringChargesPresenter
{
    public function __construct(
        private readonly RecurringCharges $charges,
        private readonly RecurringChargeDrops $drops,
    ) {}

    /**
     * @return array{charges: list<array<string, mixed>>, totals: list<array{currencyCode: string, monthly: int}>}
     */
    public function present(User $user, string $spaceId): array
    {
        $charges = $this->charges->for($user, $spaceId);
        $accounts = Account::query()->whereKey(array_unique(array_map(fn (RecurringCharge $charge): string => $charge->accountId, $charges)))->pluck('name', 'id');
        $expenseCategoryIds = $this->drops->expenseCategoryIds($user);
        $drops = ForecastRecurringDrop::query()->where('user_id', $user->id)->forSpace($spaceId)->with('adjustment')->get()->keyBy('charge_key');
        $kept = ForecastDismissal::query()->where('user_id', $user->id)->forSpace($spaceId)->where('kind', ForecastSuggestionKind::Kept)->pluck('fingerprint')->flip();
        $totals = [];

        foreach ($charges as $charge) {
            $totals[$charge->currencyCode] = ($totals[$charge->currencyCode] ?? 0) + $charge->monthly;
        }

        return [
            'charges' => array_map(fn (RecurringCharge $charge): array => [
                'key' => $charge->key,
                'name' => $charge->name,
                'accountId' => $charge->accountId,
                'accountName' => $accounts->get($charge->accountId),
                'currencyCode' => $charge->currencyCode,
                'frequency' => $charge->frequency->value,
                'amount' => $charge->amount,
                'monthly' => $charge->monthly,
                'yearly' => $charge->yearly,
                'lastChargedOn' => $charge->lastChargedOn,
                'canDrop' => $this->drops->canDrop($charge, $expenseCategoryIds),
                'drop' => $this->dropOf($drops->get($charge->key)),
                'kept' => $kept->has($charge->key),
            ], $charges),
            'totals' => array_map(fn (string $currency, int $monthly): array => ['currencyCode' => $currency, 'monthly' => $monthly], array_keys($totals), $totals),
        ];
    }

    /**
     * @return array{id: string, month: string, reduction: int}|null
     */
    private function dropOf(?ForecastRecurringDrop $drop): ?array
    {
        if ($drop === null) {
            return null;
        }

        return [
            'id' => $drop->id,
            'month' => ForecastCalendar::monthKey($drop->adjustment->starts_on->toDateString()),
            'reduction' => $drop->reduction,
        ];
    }
}
