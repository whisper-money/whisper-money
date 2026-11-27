<?php

namespace App\Services\Forecast;

use App\Enums\ForecastSuggestionKind;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the forecast's review shows: the recurring lines and transfers the
 * history suggests (step 1), the yearly ones (step 2), the accounts lines can
 * go to, and how the day-to-day spend will be estimated. Each suggestion
 * carries the `key` the review sends back to confirm or dismiss it; the
 * server reads the suggestions again then, so nothing but the key, a name and
 * a destination is taken from the browser.
 */
class ForecastReviewPresenter
{
    public function __construct(
        private readonly ForecastSuggestions $suggestions,
        private readonly ForecastAccounts $accounts,
        private readonly ForecastDailySpend $dailySpend,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user, string $spaceId): array
    {
        $today = $user->localToday()->toDateString();
        $accounts = $this->accounts->included($user, $spaceId)->keyBy('id');
        $suggestions = $this->suggestions->for($user, $spaceId, $today);
        $all = [...$suggestions['recurring'], ...$suggestions['annual']];
        $charges = Transaction::query()
            ->whereIn('id', [
                ...array_merge(...array_map(fn (ForecastCandidate $candidate): array => $candidate->transactionIds, $suggestions['annual'])),
                ...array_map(fn (ForecastCandidate $candidate): string => $candidate->transactionIds[0], $all),
            ])
            ->get(['id', 'transaction_date', 'amount'])
            ->keyBy('id');
        $present = fn (ForecastCandidate $candidate): array => $this->suggestion($candidate, $accounts, $today, $charges->get($candidate->transactionIds[0])?->transaction_date->toDateString());

        return [
            'recurring' => array_map($present, $suggestions['recurring']),
            'annual' => array_map(fn (ForecastCandidate $candidate): array => [
                ...$present($candidate),
                'evidence' => array_values(array_filter(array_map(fn (string $id): ?array => $charges->has($id) ? [
                    'date' => $charges[$id]->transaction_date->toDateString(),
                    'amount' => $charges[$id]->amount,
                ] : null, $candidate->transactionIds))),
            ], $suggestions['annual']),
            'accounts' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'currencyCode' => $account->currency_code,
            ])->values()->all(),
            'dailySpend' => $accounts->isEmpty() ? null : $this->dailySpend->for($user, $spaceId, $today, $accounts),
        ];
    }

    /**
     * @param  Collection<string, Account>  $accounts
     * @return array<string, mixed>
     */
    private function suggestion(ForecastCandidate $candidate, Collection $accounts, string $today, ?string $firstCharge): array
    {
        return [
            'key' => $candidate->reviewKey(),
            'kind' => $candidate->kind->value,
            'mergeable' => $candidate->fingerprint !== null && $candidate->kind !== ForecastSuggestionKind::Transfer,
            'name' => $candidate->name,
            'accountId' => $candidate->accountId,
            'accountName' => $accounts->get($candidate->accountId)?->name,
            'destinationAccountId' => $candidate->destinationAccountId,
            'destinationAccountName' => $candidate->destinationAccountId === null ? null : $accounts->get($candidate->destinationAccountId)?->name,
            'destinationUnknown' => $candidate->destinationUnknown,
            'currencyCode' => $candidate->currencyCode,
            'frequency' => $candidate->frequency->value,
            'anchorDay' => $candidate->anchorDay,
            'nextDate' => $candidate->nextDate,
            'amount' => $candidate->amount,
            'amountTolerance' => $candidate->amountTolerance,
            'confidence' => $candidate->confidence->value,
            'chargeCount' => count($candidate->transactionIds),
            'expectedCount' => $this->expectedCount($candidate, $today, $firstCharge),
        ];
    }

    /**
     * How many times the line was due from its first charge to today, so the
     * review can say "12 of 12 months" or "only 4 of the last 6".
     */
    private function expectedCount(ForecastCandidate $candidate, string $today, ?string $from): int
    {
        $step = $candidate->frequency->months();

        if ($step === null || $from === null) {
            return count($candidate->transactionIds);
        }

        $due = $candidate->nextDate <= $today ? 1 : 0;

        for ($periods = 1; ForecastCalendar::addMonths($candidate->nextDate, -$periods * $step) >= ForecastCalendar::monthStart($from); $periods++) {
            $due++;
        }

        return max($due, count($candidate->transactionIds));
    }
}
