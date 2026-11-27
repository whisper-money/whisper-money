<?php

namespace App\Services\Forecast;

use App\Enums\CategoryType;
use App\Enums\ForecastEstimateKind;
use App\Enums\ForecastSuggestionKind;
use App\Models\Category;
use App\Models\ForecastDismissal;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastRecurringDrop;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\RecurringCharge;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "I'm dropping it" on a recurring charge: from next month on, the spend
 * estimate the charge lives in goes down by what it costs a month, never
 * below zero. That is its parent category's row on its account, or "Other
 * spending" when the category is too small for a row of its own (or the
 * charge has none). It goes through a spend estimate adjustment "from here on",
 * the same one the user makes by hand: an open-ended one already starting
 * next month on that row is lowered, otherwise one is made at what the row
 * is planned at that month, less the charge. Dropping a charge stops
 * keeping it.
 *
 * Each drop remembers what it took off, so Undo gives back exactly that,
 * and deletes the adjustment once no drop that made it is left. When the
 * user changed the adjustment by hand since, their figure stands: Undo
 * only forgets the drop.
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
        if (! $this->canDrop($charge, $this->expenseCategoryIds($user))) {
            throw ValidationException::withMessages(['key' => __('This charge is not in an expense category, so the forecast does not count it as spend.')]);
        }

        $existing = fn (): ?ForecastRecurringDrop => ForecastRecurringDrop::query()->where('user_id', $user->id)->forSpace($spaceId)->where('charge_key', $charge->key)->first();
        $drop = $existing();

        if ($drop !== null) {
            return $drop;
        }

        $month = ForecastCalendar::monthStart($user->localToday()->toDateString(), 1);
        [$categoryId, $planned] = $this->row($user, $spaceId, $charge, ForecastCalendar::monthKey($month));

        try {
            return DB::transaction(fn (): ForecastRecurringDrop => $this->lower($user, $spaceId, $charge, $month, $categoryId, $planned));
        } catch (UniqueConstraintViolationException $exception) {
            return $existing() ?? throw $exception;
        }
    }

    public function undo(ForecastRecurringDrop $drop): void
    {
        DB::transaction(function () use ($drop): void {
            $adjustment = ForecastEstimateAdjustment::query()->lockForUpdate()->findOrFail($drop->forecast_estimate_adjustment_id);
            $others = ForecastRecurringDrop::query()->where('forecast_estimate_adjustment_id', $adjustment->id)->whereKeyNot($drop->id)->get();
            $untouched = $adjustment->ends_on === null && $adjustment->amount === $drop->adjustment_amount;

            if (! $untouched) {
                $drop->delete();

                return;
            }

            if ($others->isEmpty() && $drop->created_adjustment) {
                $adjustment->delete();

                return;
            }

            $adjustment->increment('amount', $drop->reduction);
            ForecastRecurringDrop::query()->whereKey($others->modelKeys())->update([
                'adjustment_amount' => $adjustment->amount,
                ...($drop->created_adjustment ? ['created_adjustment' => true] : []),
            ]);
            $drop->delete();
        });
    }

    /**
     * Lowers (or makes) the adjustment of the row from `$month` on, and
     * records the drop.
     */
    private function lower(User $user, string $spaceId, RecurringCharge $charge, string $month, ?string $categoryId, int $planned): ForecastRecurringDrop
    {
        $adjustment = ForecastEstimateAdjustment::query()
            ->where(['user_id' => $user->id, 'space_id' => $spaceId, 'account_id' => $charge->accountId, 'category_id' => $categoryId, 'kind' => ForecastEstimateKind::Expense])
            ->whereDate('starts_on', $month)
            ->lockForUpdate()
            ->first();

        if ($adjustment?->ends_on !== null) {
            throw ValidationException::withMessages(['key' => __('You set this category for next month only. Change its estimate instead.')]);
        }

        $created = $adjustment === null;
        $adjustment ??= new ForecastEstimateAdjustment([
            'user_id' => $user->id,
            'space_id' => $spaceId,
            'account_id' => $charge->accountId,
            'category_id' => $categoryId,
            'kind' => ForecastEstimateKind::Expense,
            'starts_on' => $month,
            'amount' => $planned,
        ]);
        $lowered = max($adjustment->amount - $charge->monthly, 0);
        $reduction = $adjustment->amount - $lowered;
        $adjustment->amount = $lowered;
        $adjustment->save();

        ForecastRecurringDrop::query()->where('forecast_estimate_adjustment_id', $adjustment->id)->update(['adjustment_amount' => $lowered]);
        ForecastDismissal::query()->where(['user_id' => $user->id, 'space_id' => $spaceId, 'fingerprint' => $charge->key, 'kind' => ForecastSuggestionKind::Kept])->delete();

        return ForecastRecurringDrop::query()->create([
            'user_id' => $user->id,
            'space_id' => $spaceId,
            'forecast_estimate_adjustment_id' => $adjustment->id,
            'charge_key' => $charge->key,
            'reduction' => $reduction,
            'adjustment_amount' => $lowered,
            'created_adjustment' => $created,
        ]);
    }

    /**
     * The row the charge lowers next month and what it is planned at then:
     * its parent category's, or "Other spending" when that has no row.
     *
     * @return array{0: string|null, 1: int}
     */
    private function row(User $user, string $spaceId, RecurringCharge $charge, string $month): array
    {
        $estimates = collect($this->plans->build($user, $spaceId, [$charge->accountId], 2)->categoryEstimates)
            ->filter(fn (CategoryEstimate $estimate): bool => ! $estimate->isIncome() && $estimate->month === $month && $estimate->accountId === $charge->accountId)
            ->keyBy(fn (CategoryEstimate $estimate): string => (string) $estimate->categoryId);
        $estimate = $estimates->get((string) $charge->categoryId) ?? $estimates->get('');

        return $estimate === null ? [$charge->categoryId, 0] : [$estimate->categoryId, (int) $estimate->planned];
    }
}
