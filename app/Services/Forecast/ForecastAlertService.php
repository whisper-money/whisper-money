<?php

namespace App\Services\Forecast;

use App\Enums\ForecastAlertLevel;
use App\Models\Account;
use App\Models\ForecastAlert;
use App\Models\ForecastItem;
use App\Models\User;
use App\Services\Forecast\Data\ForecastAlertFinding;
use App\Services\Forecast\Data\ForecastPlan;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keeps a user's stored forecast alerts in step with their forecast, and tells
 * them about the new ones.
 *
 * Each space's forecast is projected and evaluated; a problem found for the
 * first time is stored with `first_seen_at`, one already stored follows its
 * level, and one no longer found is resolved. A problem that comes back after
 * being resolved for a week starts over as a new alert, so it is told about
 * again; one that comes back sooner reopens as it was.
 * Running it again with nothing changed changes nothing.
 *
 * Only what the user can see on the forecast page counts: the month in
 * progress and the twelve after it with Pro, and without it the month in
 * progress and the next one.
 */
class ForecastAlertService
{
    /**
     * The months a user without Pro sees on the forecast page: the month in
     * progress and the next.
     */
    private const FREE_MONTHS = 2;

    /**
     * The month in progress and the twelve after it, as the page shows Pro.
     */
    private const PRO_MONTHS = 13;

    /**
     * A problem that comes back within this many days of going away is the
     * same problem flickering (a payment a few days late, a sync that lags):
     * it reopens without being told about again.
     */
    private const REOPEN_GRACE_DAYS = 7;

    public function __construct(
        private readonly ForecastPlanBuilder $plans,
        private readonly ForecastProjector $projector,
        private readonly ForecastAlertEvaluator $evaluator,
        private readonly ForecastAccounts $accounts,
        private readonly ForecastAlertNotifier $notifier,
        private readonly ForecastAlertCauses $causes,
    ) {}

    public function refresh(User $user): void
    {
        // Without a line there is nothing the user planned to warn them
        // about, and projecting them every hour would cost for nothing.
        if (! ForecastItem::query()->where('user_id', $user->id)->notArchived()->exists()) {
            $this->resolveAll($user);

            return;
        }

        $findings = $this->findings($user);

        $this->store($user, $findings);
        $this->notifier->notifyNew($user, array_map(fn (array $found): ForecastAlertFinding => $found['finding'], $findings));
    }

    /**
     * Resolve every open alert of the user, for when nothing is forecast any
     * more.
     */
    private function resolveAll(User $user): void
    {
        ForecastAlert::query()
            ->where('user_id', $user->id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    /**
     * How many months, the one in progress first, the user's alerts cover.
     */
    public static function monthsFor(User $user): int
    {
        return $user->hasProPlan() ? self::PRO_MONTHS : self::FREE_MONTHS;
    }

    /**
     * @return array<string, array{space_id: string, finding: ForecastAlertFinding}> By finding key.
     */
    private function findings(User $user): array
    {
        $months = self::monthsFor($user);
        $findings = [];

        foreach ($this->accounts->spaceIds($user) as $spaceId) {
            $plan = $this->plans->build($user, $spaceId, horizonMonths: $months);
            $projection = $this->projector->project($plan->input);

            foreach ($this->evaluate($projection['accounts'], $plan) as $finding) {
                $findings[$finding->key()] = ['space_id' => $spaceId, 'finding' => $finding];
            }
        }

        return $findings;
    }

    /**
     * Each projected account's problem months, by the one rule the forecast
     * page also warns by.
     *
     * @param  list<array{accountId: string, minimumBalance: int|null, months: list<array<string, int|string|null>>}>  $accounts
     * @return list<ForecastAlertFinding>
     */
    private function evaluate(array $accounts, ForecastPlan $plan): array
    {
        $types = Account::query()->whereKey(array_column($accounts, 'accountId'))->pluck('type', 'id');
        $findings = [];

        foreach ($accounts as $account) {
            $threshold = $this->evaluator->threshold($types[$account['accountId']], $account['minimumBalance']);

            foreach ($account['months'] as $month) {
                $findings[] = $this->finding($account['accountId'], $month, $threshold, $plan);
            }
        }

        return array_values(array_filter($findings));
    }

    /**
     * @param  array<string, int|string|null>  $month
     */
    private function finding(string $accountId, array $month, ?int $threshold, ForecastPlan $plan): ?ForecastAlertFinding
    {
        $level = $this->evaluator->levelOf($month, $threshold);

        if ($level === null) {
            return null;
        }

        $key = (string) $month['month'];

        if ($level === ForecastAlertLevel::Soft) {
            // Still at or above zero at its lowest: it is the wanted minimum
            // the balance falls under, not zero.
            return new ForecastAlertFinding($accountId, $key, $level, (int) $month['pessimisticMin'] >= 0);
        }

        $lowest = (int) $month['min'];
        $lowestOn = $month['minDate'] === null ? null : (string) $month['minDate'];

        return new ForecastAlertFinding($accountId, $key, $level, $lowest >= 0, $lowest, $lowestOn, $this->causes->for($plan, $accountId, $key));
    }

    /**
     * @param  array<string, array{space_id: string, finding: ForecastAlertFinding}>  $findings
     */
    private function store(User $user, array $findings): void
    {
        $now = now();
        $stored = $this->stored($user);

        foreach ($findings as $key => $found) {
            $this->upsert($stored->get($key), $user, $found['space_id'], $found['finding'], $now);
        }

        $gone = $stored->filter(fn (ForecastAlert $alert, string $key): bool => $alert->resolved_at === null && ! isset($findings[$key]));

        if ($gone->isNotEmpty()) {
            ForecastAlert::query()->whereKey($gone->modelKeys())->update(['resolved_at' => $now]);
        }
    }

    /**
     * @return Collection<string, ForecastAlert> By account and `Y-m` month.
     */
    private function stored(User $user): Collection
    {
        return ForecastAlert::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (ForecastAlert $alert): string => $alert->key());
    }

    /**
     * @return array<string, mixed>
     */
    private function reopening(ForecastAlert $alert, ForecastAlertFinding $finding, CarbonInterface $now): array
    {
        $flicker = $alert->resolved_at->gte($now->copy()->subDays(self::REOPEN_GRACE_DAYS));

        // A new problem is not one the user asked to be reminded of later.
        return $flicker
            ? ['level' => $finding->level, 'resolved_at' => null, ...ForecastAlert::detailsFrom($finding)]
            : ['level' => $finding->level, 'first_seen_at' => $now, 'notified_at' => null, 'resolved_at' => null, 'snoozed_until' => null, ...ForecastAlert::detailsFrom($finding)];
    }

    /**
     * The snooze the account's problem is under from an earlier month on, if
     * any: a month that turns short after the user asked to be reminded later
     * is the same problem, and stays put away with it.
     */
    private function snoozeCovering(ForecastAlertFinding $finding): ?string
    {
        $until = ForecastAlert::query()
            ->where('account_id', $finding->accountId)
            ->whereNull('resolved_at')
            ->where('month', '<', $finding->month.'-01')
            ->where('snoozed_until', '>', now()->toDateString())
            ->max('snoozed_until');

        return $until === null ? null : (string) $until;
    }

    private function upsert(?ForecastAlert $alert, User $user, string $spaceId, ForecastAlertFinding $finding, CarbonInterface $now): void
    {
        if ($alert === null) {
            ForecastAlert::query()->createOrFirst(
                ['account_id' => $finding->accountId, 'month' => $finding->month.'-01'],
                [
                    'user_id' => $user->id,
                    'space_id' => $spaceId,
                    'level' => $finding->level,
                    'first_seen_at' => $now,
                    'snoozed_until' => $this->snoozeCovering($finding),
                    ...ForecastAlert::detailsFrom($finding),
                ],
            );

            return;
        }

        // Saved only when something changed: with nothing changed a run
        // writes nothing.
        $alert->fill($alert->resolved_at !== null
            ? $this->reopening($alert, $finding, $now)
            : ['level' => $finding->level, ...ForecastAlert::detailsFrom($finding)]);

        if ($alert->isDirty()) {
            $alert->save();
        }
    }
}
