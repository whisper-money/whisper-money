<?php

namespace App\Services\Forecast;

use App\Enums\ForecastMatchedBy;
use App\Models\AutomationRule;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Services\AutomationRuleService;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\MatchCandidate;
use App\Services\Forecast\Data\Occurrence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Pairs a real transaction with the forecast occurrence it paid.
 *
 * The candidates are the occurrences of the owner's live lines on the
 * transaction's account, or on the destination account of a transfer, whose
 * expected date is within the line's `date_window_days` of the transaction
 * (the window crosses month boundaries: a salary paid on 30 September pays
 * the October occurrence), whose amount on that account is within the line's
 * `amount_tolerance`, and whose recognition rule (`match_rules_json`, in the
 * format of `AutomationRule::rules_json`) matches the transaction. A line
 * without a rule is recognised by amount and date alone, and so is the
 * receiving leg of a transfer, since the rule describes the sending one.
 *
 * An occurrence takes one transaction per account, and a transaction pays one
 * occurrence. The closest free occurrence wins, by date and then by amount;
 * when two are equally close the transaction is left unmatched for the user
 * to pair. The stored `deviation` is actual minus expected, signed from the
 * line's own account, so a transfer's receiving leg reads the same as its
 * sending one.
 *
 * A pairing the user made themselves stands: automatic matching never
 * replaces it, unless the transaction moved to an account the line does not
 * touch.
 */
class ForecastMatcher
{
    /**
     * Each user's live lines, memoized for the lifetime of this instance, so
     * matching a batch reads them once. Resolved fresh per job, so a line
     * created mid-batch is picked up by the next run.
     *
     * @var array<string, Collection<int, ForecastItem>>
     */
    private array $itemsByUser = [];

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly OccurrenceExpander $expander,
        private readonly AutomationRuleService $rules,
    ) {}

    /**
     * Match the transaction again from scratch, dropping an automatic pairing
     * that no longer holds.
     */
    public function match(Transaction $transaction): ?ForecastMatch
    {
        $existing = ForecastMatch::query()->where('transaction_id', $transaction->id)->first();

        if ($existing?->matched_by === ForecastMatchedBy::User && $this->stillMovesLine($existing, $transaction)) {
            return $existing;
        }

        $existing?->delete();

        return $this->pair($transaction);
    }

    /**
     * Match each transaction that has no pairing yet.
     *
     * @param  iterable<Transaction>  $transactions
     * @return int How many were matched.
     */
    public function matchUnmatched(iterable $transactions): int
    {
        $matched = 0;

        foreach ($transactions as $transaction) {
            if (ForecastMatch::query()->where('transaction_id', $transaction->id)->doesntExist() && $this->pair($transaction) !== null) {
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * A user's pairing stands until the transaction moves to an account the
     * line does not touch.
     */
    private function stillMovesLine(ForecastMatch $match, Transaction $transaction): bool
    {
        $item = $match->forecastItem;

        return $item !== null && in_array($transaction->account_id, [$item->account_id, $item->destination_account_id], true);
    }

    /**
     * A deleted transaction pays nothing, so its pairing goes whoever made
     * it. Splitting deletes the original: its parts are matched on their own.
     */
    public function unmatch(string $transactionId): void
    {
        ForecastMatch::query()->where('transaction_id', $transactionId)->delete();
    }

    /**
     * Pair a transaction that has no pairing. Whether an occurrence is free
     * is read and then written, so pairings on one account are made one at a
     * time: the listener, the import job and the hourly command can run at
     * once, and two payments must not both take the same occurrence.
     */
    private function pair(Transaction $transaction): ?ForecastMatch
    {
        if ($transaction->trashed() || $transaction->amount === 0) {
            return null;
        }

        return Cache::lock('forecast-match:'.$transaction->account_id, self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, function () use ($transaction): ?ForecastMatch {
                $candidate = $this->bestCandidate($transaction);

                return $candidate === null ? null : $this->store($transaction, $candidate);
            });
    }

    private function bestCandidate(Transaction $transaction): ?MatchCandidate
    {
        $items = $this->itemsFor($transaction);

        if ($items->isEmpty()) {
            return null;
        }

        $candidates = $this->candidates($transaction, $items);
        usort($candidates, fn (MatchCandidate $a, MatchCandidate $b): int => $a->compare($b));

        if ($candidates === [] || (isset($candidates[1]) && $candidates[0]->compare($candidates[1]) === 0)) {
            return null;
        }

        return $candidates[0];
    }

    /**
     * @param  Collection<int, ForecastItem>  $items
     * @return list<MatchCandidate>
     */
    private function candidates(Transaction $transaction, Collection $items): array
    {
        $date = $transaction->transaction_date->toDateString();
        $widest = (int) $items->max('date_window_days');
        $occurrences = $this->expander->expand(
            $items->map(ExpandableItem::fromModel(...)),
            Carbon::parse($date)->subDays($widest)->toDateString(),
            Carbon::parse($date)->addDays($widest)->toDateString(),
        );
        $taken = $this->takenOccurrences($transaction, $items);
        $itemsById = $items->keyBy('id');
        $candidates = [];

        foreach ($occurrences as $occurrence) {
            $candidate = isset($taken[$this->occurrenceKey($occurrence->itemId, $occurrence->occurrenceDate)])
                ? null
                : $this->candidate($transaction, $itemsById[$occurrence->itemId], $occurrence);

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function candidate(Transaction $transaction, ForecastItem $item, Occurrence $occurrence): ?MatchCandidate
    {
        $isSendingLeg = $occurrence->accountId === $transaction->account_id;
        $actual = $isSendingLeg ? $transaction->amount : -$transaction->amount;
        $deviation = $actual - $occurrence->amount;
        $dateDistance = (int) Carbon::parse($occurrence->date)->diffInDays(Carbon::parse($transaction->transaction_date->toDateString()), true);

        if ($dateDistance > $item->date_window_days || abs($deviation) > $item->amount_tolerance) {
            return null;
        }

        if ($isSendingLeg && ! $this->ruleMatches($item, $transaction)) {
            return null;
        }

        return new MatchCandidate($item->id, $occurrence->occurrenceDate, $dateDistance, abs($deviation), $deviation);
    }

    private function ruleMatches(ForecastItem $item, Transaction $transaction): bool
    {
        if (empty($item->match_rules_json)) {
            return true;
        }

        return $this->rules->ruleMatches(new AutomationRule(['rules_json' => $item->match_rules_json]), $transaction);
    }

    /**
     * The occurrences of these lines another transaction on the same account
     * already paid, keyed `itemId|occurrenceDate`.
     *
     * @param  Collection<int, ForecastItem>  $items
     * @return array<string, true>
     */
    private function takenOccurrences(Transaction $transaction, Collection $items): array
    {
        return ForecastMatch::query()
            ->whereIn('forecast_item_id', $items->modelKeys())
            ->where('transaction_id', '!=', $transaction->id)
            ->whereHas('transaction', fn ($query) => $query->where('account_id', $transaction->account_id))
            ->get(['forecast_item_id', 'occurrence_date'])
            ->mapWithKeys(fn (ForecastMatch $match): array => [$this->occurrenceKey($match->forecast_item_id, $match->occurrence_date->toDateString()) => true])
            ->all();
    }

    private function occurrenceKey(string $itemId, string $occurrenceDate): string
    {
        return $itemId.'|'.$occurrenceDate;
    }

    /**
     * The owner's live lines that move the transaction's account.
     *
     * @return Collection<int, ForecastItem>
     */
    private function itemsFor(Transaction $transaction): Collection
    {
        $this->itemsByUser[$transaction->user_id] ??= ForecastItem::query()
            ->where('user_id', $transaction->user_id)
            ->notArchived()
            ->with(['versions', 'overrides'])
            ->get();

        return $this->itemsByUser[$transaction->user_id]
            ->filter(fn (ForecastItem $item): bool => $item->account_id === $transaction->account_id
                || $item->destination_account_id === $transaction->account_id)
            ->values();
    }

    /**
     * A concurrent run may have paired the transaction first: its pairing is
     * kept.
     */
    private function store(Transaction $transaction, MatchCandidate $candidate): ForecastMatch
    {
        try {
            return ForecastMatch::query()->create([
                'forecast_item_id' => $candidate->itemId,
                'occurrence_date' => $candidate->occurrenceDate,
                'transaction_id' => $transaction->id,
                'matched_by' => ForecastMatchedBy::Auto,
                'deviation' => $candidate->deviation,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ForecastMatch::query()->where('transaction_id', $transaction->id)->firstOrFail();
        }
    }
}
