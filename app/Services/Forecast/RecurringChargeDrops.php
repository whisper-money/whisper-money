<?php

namespace App\Services\Forecast;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastRecurringDrop;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\RecurringCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "I'm dropping it" on a recurring charge: from next month on, the spend
 * estimate the charge lives in goes down by what it costs a month, never
 * below zero. That is its parent category's row on its account, or "Other
 * spending" when the category is too small for a row of its own (or the
 * charge has none). It goes through an estimate adjustment "from here on",
 * the same one the user makes by hand: an open-ended one already starting
 * next month on that row is lowered, otherwise one is made at what the row
 * is planned at that month, less the charge. Each drop remembers what it
 * took off, so Undo gives back exactly that, and deletes the adjustment
 * once no drop that made it is left.
 */
class RecurringChargeDrops
{
    public function __construct(private readonly ForecastPlanBuilder $plans) {}

    /**
     * The user's expense categories, the ones a charge can be dropped from.
     *
     * @return array<string, int> Keyed by id.
     */
    public function expenseCategoryIds(User $user): array
    {
        return Category::query()->where('user_id', $user->id)->where('type', CategoryType::Expense)->pluck('id')->flip()->all();
    }

    /**
     * Whether the charge is spend the estimates count: in no category or
     * in an expense one.
     *
     * @param  array<string, int>  $expenseCategoryIds  See expenseCategoryIds().
     */
    public function canDrop(RecurringCharge $charge, array $expenseCategoryIds): bool
    {
        return $charge->categoryId === null || isset($expenseCategoryIds[$charge->categoryId]);
    }

    public function drop(User $user, string $spaceId, RecurringCharge $charge): ForecastRecurringDrop
    {
        $month = ForecastCalendar::monthStart($user->localToday()->toDateString(), 1);
        [$categoryId, $planned] = $this->row($user, $spaceId, $charge, ForecastCalendar::monthKey($month));

        return DB::transaction(function () use ($user, $spaceId, $charge, $month, $categoryId, $planned): ForecastRecurringDrop {
            $existing = ForecastRecurringDrop::query()->where('user_id', $user->id)->forSpace($spaceId)->where('charge_key', $charge->key)->first();

            if ($existing !== null) {
                return $existing;
            }

            $adjustment = ForecastEstimateAdjustment::query()
                ->where(['user_id' => $user->id, 'space_id' => $spaceId, 'account_id' => $charge->accountId, 'category_id' => $categoryId])
                ->whereDate('starts_on', $month)
                ->first();

            if ($adjustment !== null && $adjustment->ends_on !== null) {
                throw ValidationException::withMessages(['key' => __('You set this category for next month only. Change its estimate instead.')]);
            }

            $created = $adjustment === null;
            $adjustment ??= new ForecastEstimateAdjustment([
                'user_id' => $user->id,
                'space_id' => $spaceId,
                'account_id' => $charge->accountId,
                'category_id' => $categoryId,
                'starts_on' => $month,
                'amount' => $planned,
            ]);
            $lowered = max($adjustment->amount - $charge->monthly, 0);
            $reduction = $adjustment->amount - $lowered;
            $adjustment->amount = $lowered;
            $adjustment->save();

            return ForecastRecurringDrop::query()->create([
                'user_id' => $user->id,
                'space_id' => $spaceId,
                'forecast_estimate_adjustment_id' => $adjustment->id,
                'charge_key' => $charge->key,
                'reduction' => $reduction,
                'created_adjustment' => $created,
            ]);
        });
    }

    public function undo(ForecastRecurringDrop $drop): void
    {
        DB::transaction(function () use ($drop): void {
            $adjustment = $drop->adjustment;
            $others = ForecastRecurringDrop::query()
                ->where('forecast_estimate_adjustment_id', $adjustment->id)
                ->whereKeyNot($drop->id)
                ->get();

            if ($others->isEmpty() && $drop->created_adjustment) {
                $adjustment->delete();

                return;
            }

            $adjustment->increment('amount', $drop->reduction);

            if ($drop->created_adjustment) {
                $others->first()?->update(['created_adjustment' => true]);
            }

            $drop->delete();
        });
    }

    /**
     * The row the charge lowers next month and what it is planned at then:
     * its parent category's, or "Other spending" when that has no row.
     *
     * @return array{0: string|null, 1: int}
     */
    private function row(User $user, string $spaceId, RecurringCharge $charge, string $month): array
    {
        $estimates = array_values(array_filter(
            $this->plans->build($user, $spaceId, [$charge->accountId], 2)->categoryEstimates,
            fn (CategoryEstimate $estimate): bool => ! $estimate->isIncome() && $estimate->month === $month && $estimate->accountId === $charge->accountId,
        ));

        foreach ([$charge->categoryId, null] as $categoryId) {
            foreach ($estimates as $estimate) {
                if ($estimate->categoryId === $categoryId) {
                    return [$categoryId, (int) $estimate->planned];
                }
            }
        }

        return [$charge->categoryId, 0];
    }
}
