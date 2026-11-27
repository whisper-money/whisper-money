<?php

namespace App\Services\Forecast;

use App\Enums\ForecastSuggestionKind;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\Data\RecurringCharge;
use Illuminate\Support\Collection;

/**
 * What the user pays again and again in one space: RecurringDetector run
 * live over the last 18 months of the accounts the forecast includes (the
 * window the review reads), keeping only the outgoing charges that are not
 * transfers between the user's accounts. Each one costs the median of its
 * last three charges; the most expensive a month comes first. It never
 * touches the projection, and nothing is stored.
 */
class RecurringCharges
{
    private const HISTORY_MONTHS = 18;

    private const AMOUNT_CHARGES = 3;

    public function __construct(
        private readonly ForecastHistory $history,
        private readonly RecurringDetector $detector,
        private readonly TransferPairer $pairer,
        private readonly DescriptionFingerprint $fingerprint,
        private readonly ForecastAccounts $accounts,
    ) {}

    /**
     * @return list<RecurringCharge>
     */
    public function for(User $user, string $spaceId, ?string $today = null): array
    {
        $today ??= $user->localToday()->toDateString();
        $accountIds = $this->accounts->included($user, $spaceId)->modelKeys();
        $transactions = $this->history->transactions($user, $spaceId, ForecastCalendar::monthStart($today, -self::HISTORY_MONTHS), $today, $accountIds);
        $candidates = $this->detector->detect($this->fingerprint->groups($transactions), $this->pairer->pair($transactions), $today);
        $byId = $transactions->keyBy('id');
        $parents = $this->history->parentCategories($user);

        $charges = array_map(
            fn (ForecastCandidate $candidate): RecurringCharge => $this->chargeOf($candidate, $byId, $parents),
            array_values(array_filter($candidates, fn (ForecastCandidate $candidate): bool => $candidate->amount < 0 && $candidate->kind !== ForecastSuggestionKind::Transfer)),
        );

        usort($charges, fn (RecurringCharge $a, RecurringCharge $b): int => [$b->monthly, $a->name] <=> [$a->monthly, $b->name]);

        return $charges;
    }

    /**
     * The charge with `$key`, if the user still pays it in the space.
     */
    public function find(User $user, string $spaceId, string $key): ?RecurringCharge
    {
        foreach ($this->for($user, $spaceId) as $charge) {
            if ($charge->key === $key) {
                return $charge;
            }
        }

        return null;
    }

    /**
     * @param  Collection<string, Transaction>  $byId
     * @param  array<string, string>  $parents
     */
    private function chargeOf(ForecastCandidate $candidate, Collection $byId, array $parents): RecurringCharge
    {
        $recent = array_slice($candidate->transactionIds, -self::AMOUNT_CHARGES);
        $amount = (int) round(collect($recent)->map(fn (string $id): int => abs((int) $byId->get($id)?->amount))->median());
        $yearly = $amount * (int) $candidate->frequency->perYear();

        return new RecurringCharge(
            key: RecurringCharge::keyOf($candidate->accountId, $candidate->identity()),
            name: $candidate->name,
            accountId: $candidate->accountId,
            currencyCode: $candidate->currencyCode,
            categoryId: $candidate->categoryId === null ? null : ($parents[$candidate->categoryId] ?? null),
            frequency: $candidate->frequency,
            amount: $amount,
            monthly: (int) round($yearly / 12),
            yearly: $yearly,
            lastChargedOn: $candidate->lastChargedOn,
        );
    }
}
