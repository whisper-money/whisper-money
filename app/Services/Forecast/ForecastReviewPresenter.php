<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the forecast's review shows, for the account being reviewed or for
 * every included account: whether too much money has no category for the
 * forecast to be accurate (`uncategorized`, shown before anything else),
 * how next month is estimated per category (step 1, see
 * ForecastReviewEstimates), the yearly payments the history suggests
 * (step 2), and the accounts payments can go to. Each yearly suggestion
 * carries the `key` the review sends back to add or dismiss it; the server
 * reads the suggestions again then, so nothing but the key is taken from
 * the browser.
 */
class ForecastReviewPresenter
{
    public function __construct(
        private readonly ForecastSuggestions $suggestions,
        private readonly ForecastAccounts $accounts,
        private readonly ForecastReviewEstimates $estimates,
        private readonly ForecastUncategorizedShare $uncategorized,
    ) {}

    /**
     * @param  string|null  $accountId  The account reviewed; null, or one the forecast leaves out, reviews every included account.
     * @return array<string, mixed>
     */
    public function present(User $user, string $spaceId, ?string $accountId = null): array
    {
        $today = $user->localToday()->toDateString();
        $accounts = $this->accounts->included($user, $spaceId)->keyBy('id');
        $accountId = $accounts->has((string) $accountId) ? $accountId : null;
        $reviewed = $accountId === null ? $accounts->modelKeys() : [$accountId];

        return [
            'accountId' => $accountId,
            'uncategorized' => $accounts->isEmpty() ? null : $this->uncategorized->for($user, $spaceId, $reviewed, $today),
            'estimates' => $accounts->isEmpty() ? null : $this->estimates->for($user, $spaceId, $accounts, $accountId, $today),
            'annual' => $this->annual($this->suggestions->for($user, $spaceId, $today), $accounts),
            'accounts' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'currencyCode' => $account->currency_code,
            ])->values()->all(),
        ];
    }

    /**
     * The yearly suggestions, each with the charges it stands on.
     *
     * @param  list<ForecastCandidate>  $suggestions
     * @param  Collection<string, Account>  $accounts
     * @return list<array<string, mixed>>
     */
    private function annual(array $suggestions, Collection $accounts): array
    {
        $charges = Transaction::query()
            ->whereIn('id', array_merge([], ...array_map(fn (ForecastCandidate $candidate): array => $candidate->transactionIds, $suggestions)))
            ->get(['id', 'transaction_date', 'amount'])
            ->keyBy('id');

        return array_map(fn (ForecastCandidate $candidate): array => [
            'key' => $candidate->reviewKey(),
            'name' => $candidate->name,
            'accountId' => $candidate->accountId,
            'accountName' => $accounts->get($candidate->accountId)?->name,
            'currencyCode' => $candidate->currencyCode,
            'nextDate' => $candidate->nextDate,
            'amount' => $candidate->amount,
            'evidence' => array_values(array_filter(array_map(fn (string $id): ?array => $charges->has($id) ? [
                'date' => $charges[$id]->transaction_date->toDateString(),
                'amount' => $charges[$id]->amount,
            ] : null, $candidate->transactionIds))),
        ], $suggestions);
    }
}
