<?php

namespace App\Services\Stats;

use App\Models\StuckCohortSnapshot;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

class StuckCohortReportCollector
{
    /**
     * Compute this week's stuck-cohort snapshot, persist it, and compare it
     * against the most recent previous snapshot.
     *
     * A user is "stuck" when they have at least one non-deleted banking
     * connection but no valid subscription, i.e. they connected a bank but
     * never made it past the paywall. The percentage is measured against
     * users who completed onboarding (`onboarded_at` not null), which is the
     * population that has actually reached the paywall.
     *
     * @return array{
     *     snapshot: StuckCohortSnapshot,
     *     previous: ?StuckCohortSnapshot,
     *     pctDelta: ?float,
     *     stuckDelta: ?int,
     * }
     */
    public function collect(): array
    {
        $onboardedCount = User::query()
            ->whereNotNull('onboarded_at')
            ->count();

        $stuckCount = User::query()
            ->whereNotNull('onboarded_at')
            ->whereHas('bankingConnections')
            ->whereDoesntHave('subscriptions', function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
                        ->orWhere(function (Builder $query): void {
                            $query->where('stripe_status', 'canceled')
                                ->where('ends_at', '>', now());
                        });
                });
            })
            ->count();

        $stuckPct = $onboardedCount > 0
            ? round($stuckCount / $onboardedCount * 100, 2)
            : 0.0;

        $previous = StuckCohortSnapshot::query()
            ->whereDate('date', '<', today())
            ->orderByDesc('date')
            ->first();

        $snapshot = StuckCohortSnapshot::query()->updateOrCreate(
            ['date' => today()],
            [
                'onboarded_count' => $onboardedCount,
                'stuck_count' => $stuckCount,
                'stuck_pct' => $stuckPct,
            ],
        );

        return [
            'snapshot' => $snapshot,
            'previous' => $previous,
            'pctDelta' => $previous !== null ? round($stuckPct - (float) $previous->stuck_pct, 2) : null,
            'stuckDelta' => $previous !== null ? $stuckCount - $previous->stuck_count : null,
        ];
    }
}
