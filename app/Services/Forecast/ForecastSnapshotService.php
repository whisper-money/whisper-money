<?php

namespace App\Services\Forecast;

use App\Models\ForecastSnapshot;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\Occurrence;
use Illuminate\Support\Collection;

/**
 * Freezes each month's plan when the month opens, for "expected vs actual"
 * to compare against: every occurrence of a line in the month with its
 * planned amount, and every estimated-rest row of the month. One snapshot
 * per user, space and month, projected from the first day of the month with
 * the lines and history as they stand when it is frozen: normally within the
 * hour the month opens, later when the flag was turned on mid-month.
 *
 * Months are the owner's calendar months (`localToday()`), so a month opens
 * at the owner's midnight, not the server's. Freezing is safe to repeat: an
 * existing snapshot is found on its (user, space, month) key and never
 * rewritten.
 */
class ForecastSnapshotService
{
    public function __construct(
        private readonly ForecastPlanBuilder $plans,
        private readonly ForecastAccounts $accounts,
    ) {}

    /**
     * Freeze the owner's month in progress in each space they hold accounts
     * in.
     *
     * @return Collection<int, ForecastSnapshot>
     */
    public function freezeCurrentMonth(User $user): Collection
    {
        $month = ForecastCalendar::monthStart($user->localToday()->toDateString());

        return $this->accounts->spaceIds($user)
            ->map(fn (string $spaceId): ForecastSnapshot => $this->freeze($user, $spaceId, $month))
            ->values();
    }

    /**
     * @param  string  $month  Any day of the month, `Y-m-d`.
     */
    public function freeze(User $user, string $spaceId, string $month): ForecastSnapshot
    {
        $month = ForecastCalendar::monthStart($month);
        $key = ['user_id' => $user->id, 'space_id' => $spaceId, 'month' => $month];

        // Checked first rather than left to createOrFirst: building the plan
        // reads months of history, which a frozen month does not need.
        return ForecastSnapshot::query()->where($key)->first()
            ?? ForecastSnapshot::query()->createOrFirst($key, [
                'payload' => $this->payload($user, $spaceId, $month),
                'frozen_at' => now(),
            ]);
    }

    /**
     * @return array{month: string, occurrences: list<array<string, mixed>>, estimates: list<array<string, mixed>>}
     */
    private function payload(User $user, string $spaceId, string $month): array
    {
        $plan = $this->plans->build($user, $spaceId, horizonMonths: 1, startDate: $month);
        $monthKey = ForecastCalendar::monthKey($month);

        return [
            'month' => $monthKey,
            'occurrences' => array_values(array_map(
                fn (Occurrence $occurrence): array => $occurrence->toArray(),
                array_filter($plan->input->occurrences, fn (Occurrence $occurrence): bool => ForecastCalendar::monthKey($occurrence->date) === $monthKey),
            )),
            'estimates' => array_values(array_map(
                fn (CategoryEstimate $estimate): array => [
                    'accountId' => $estimate->accountId,
                    'categoryId' => $estimate->categoryId,
                    'kind' => $estimate->kind->value,
                    'amount' => $estimate->amount,
                    'basis' => $estimate->basis->value,
                ],
                array_filter($plan->categoryEstimates, fn (CategoryEstimate $estimate): bool => $estimate->month === $monthKey),
            )),
        ];
    }
}
