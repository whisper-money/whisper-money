<?php

namespace App\Services\Forecast\Data;

use App\Models\Account;
use App\Models\User;
use App\Services\Forecast\ForecastAccounts;
use Illuminate\Database\Eloquent\Collection;

/**
 * What every view of the forecast page is built from: the plan of the
 * included accounts, each one projected in its own currency, and the
 * warnings read over the months shown.
 */
final readonly class ForecastContext
{
    /**
     * @param  Collection<int, Account>  $included
     * @param  array<string, array{accountId: string, minimumBalance: int|null, months: list<array<string, int|string|null>>}>  $projected  By account id.
     * @param  array<string, int|null>  $thresholds  By account id.
     * @param  array<string, array{level: string, month: string|null, min: int|null, minDate: string|null, softMonths: list<string>}>  $alerts  By account id.
     */
    public function __construct(
        public User $user,
        public string $spaceId,
        public string $today,
        public Collection $included,
        public ForecastPlan $plan,
        public array $projected,
        public int $visibleCount,
        public array $thresholds,
        public array $alerts,
    ) {}

    /**
     * @return list<array<string, int|string|null>>
     */
    public function visibleMonths(string $accountId): array
    {
        return array_slice($this->projected[$accountId]['months'], 0, $this->visibleCount);
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function lockedMonths(string $accountId): array
    {
        return array_slice($this->projected[$accountId]['months'], $this->visibleCount);
    }

    /**
     * An account as the page names it, with the balance its warnings read
     * against (none for an account that never warns) and whether it is
     * projected only.
     *
     * @return array{id: string, name: string, type: mixed, currencyCode: string, projectionOnly: bool, minimumBalance: int|null, annualRateBps: int|null, alertThreshold: int|null}
     */
    public function accountSummary(Account $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type,
            'currencyCode' => $account->currency_code,
            'projectionOnly' => ForecastAccounts::isProjectionOnly($account->type),
            'minimumBalance' => $this->projected[$account->id]['minimumBalance'],
            'annualRateBps' => $this->projectionAccount($account->id)->annualRateBps,
            'trend' => $this->trend($account->id),
            'alertThreshold' => $this->thresholds[$account->id],
        ];
    }

    /**
     * The account's balance trend as the page shows it (see
     * BalanceTrend::toArray()), or null when it is projected from its
     * movements, or from its yearly rate for lack of a line.
     *
     * @return array{monthly: int|null, band: int|null, points: int}|null
     */
    public function trend(string $accountId): ?array
    {
        $trend = $this->plan->trends[$accountId] ?? null;

        if ($trend === null || (! $trend->isProjected() && ($this->projectionAccount($accountId)->annualRateBps ?? 0) !== 0)) {
            return null;
        }

        return $trend->toArray();
    }

    public function projectionAccount(string $accountId): ProjectionAccount
    {
        return collect($this->plan->input->accounts)->first(fn (ProjectionAccount $candidate): bool => $candidate->id === $accountId);
    }
}
