<?php

namespace App\Services\Forecast;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Enums\ForecastItemSource;
use App\Enums\ForecastMatchedBy;
use App\Models\ForecastItem;
use App\Models\ForecastItemVersion;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\Data\Occurrence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Stores the lines the review confirms.
 *
 * A line confirmed from suggestions keeps the first one's identity as its
 * fingerprint, so it is not suggested again, and recognises the charges of
 * all of them. Its schedule starts back at the first charge it was built
 * from, and those charges are paired with its occurrences right away: the
 * estimated rest leaves paired charges out, so a charge left unpaired would
 * be counted twice, once by the line and once by the median. The pairings
 * are stored as the user's, since confirming the line is their answer, so
 * matching again never drops them.
 *
 * Several suggestions merged into one line are separate charges on the same
 * schedule that all keep coming (a stopped one is never suggested), such as
 * three subscriptions: the line's amount is their sum, and each occurrence
 * takes one payment of each (see ForecastItem::takesSeveralPayments()). Its
 * tolerance lets each payment match on its own, so it covers the gap between
 * the sum and the smallest of them. The one with the largest amount gives
 * the schedule and the line's fingerprint.
 */
class ForecastLineWriter
{
    /**
     * How far around a charge its occurrence is looked for when pairing it.
     */
    private const PAIRING_DAYS = 45;

    /**
     * How far from its date a detected line's payment is recognised: the
     * column's default, which merges are checked against too.
     */
    public const DATE_WINDOW_DAYS = 3;

    /**
     * How early the first charge may have paid the schedule's first date (a
     * salary paid on the 30th for the next month's 1st).
     */
    private const EARLY_DAYS = 7;

    /**
     * How many days a yearly or one-off line entered by hand may be paid off
     * its date, and how far its amount may stray, as a share of it.
     */
    private const MANUAL_WINDOW_DAYS = 7;

    private const MANUAL_TOLERANCE_SHARE = 0.10;

    public function __construct(
        private readonly OccurrenceExpander $expander,
        private readonly DescriptionFingerprint $fingerprints,
    ) {}

    /**
     * @param  non-empty-list<ForecastCandidate>  $candidates  All on the same account.
     * @param  string|null  $destinationAccountId  For a transfer whose other account the history does not show.
     */
    public function fromCandidates(User $user, string $spaceId, array $candidates, ?string $name = null, ?string $destinationAccountId = null): ForecastItem
    {
        $primary = self::primaryOf($candidates);
        $samples = Transaction::query()->whereIn('id', array_merge(...array_map(fn (ForecastCandidate $candidate): array => $candidate->transactionIds, $candidates)))->get()->keyBy('id');
        $destination = $primary->destinationAccountId
            ?? ($primary->destinationUnknown && $destinationAccountId !== $primary->accountId ? $destinationAccountId : null);
        $anchor = $this->anchorBefore($primary, $samples);

        $item = ForecastItem::query()->create([
            'user_id' => $user->id,
            'space_id' => $spaceId,
            'account_id' => $primary->accountId,
            'destination_account_id' => $destination,
            'destination_external' => $destination === null && $primary->destinationUnknown,
            'category_id' => $primary->categoryId,
            'name' => $this->name($name, $primary->name),
            'kind' => $primary->frequency === ForecastFrequency::Once ? ForecastItemKind::OneOff : ForecastItemKind::Recurring,
            'frequency' => $primary->frequency,
            'anchor_date' => $anchor,
            'source' => ForecastItemSource::Detected,
            'confidence' => $primary->confidence,
            'match_rules_json' => ForecastMatchRules::forFingerprints(array_map(fn (ForecastCandidate $candidate): array => [
                'fingerprint' => $candidate->fingerprint,
                'field' => $this->fieldOf($candidate, $samples),
            ], $candidates)),
            'date_window_days' => self::DATE_WINDOW_DAYS,
            'amount_tolerance' => $this->tolerance($candidates),
            'fingerprint' => $primary->identity(),
        ]);

        $this->addVersion($item, $anchor, array_sum(array_map(fn (ForecastCandidate $candidate): int => $candidate->amount, $candidates)));
        $this->pairCharges($item, $samples);

        return $item;
    }

    /**
     * Where a suggestion's fingerprint was read from: the creditor name when
     * its last charge's creditor gives it, the description otherwise.
     *
     * @param  Collection<string, Transaction>  $samples
     * @return 'creditor_name'|'description'
     */
    private function fieldOf(ForecastCandidate $candidate, Collection $samples): string
    {
        $sample = $samples->get($candidate->transactionIds[array_key_last($candidate->transactionIds)]);

        return $sample !== null && $this->fingerprints->of(null, $sample->creditor_name) === $candidate->fingerprint
            ? 'creditor_name'
            : 'description';
    }

    /**
     * The suggestion that gives a merged line its schedule and fingerprint:
     * the one with the largest amount.
     *
     * @param  non-empty-list<ForecastCandidate>  $candidates
     */
    public static function primaryOf(array $candidates): ForecastCandidate
    {
        usort($candidates, fn (ForecastCandidate $a, ForecastCandidate $b): int => abs($b->amount) <=> abs($a->amount));

        return $candidates[0];
    }

    /**
     * @param  non-empty-list<ForecastCandidate>  $candidates
     */
    private function tolerance(array $candidates): int
    {
        $parts = array_map(fn (ForecastCandidate $candidate): int => abs($candidate->amount), $candidates);
        $ownSpread = array_sum(array_map(fn (ForecastCandidate $candidate): int => $candidate->amountTolerance, $candidates));

        return count($candidates) === 1 ? $ownSpread : $ownSpread + array_sum($parts) - min($parts);
    }

    /**
     * A yearly payment, or a one-off, the user typed in: on the 1st of the
     * month they gave, the next time it comes, or today when it is this
     * month. Amount signed from the account.
     */
    public function manual(User $user, string $spaceId, string $accountId, string $name, int $amount, int $month, ForecastFrequency $frequency): ForecastItem
    {
        $anchor = $this->nextDateIn($user->localToday()->toDateString(), $month);

        $item = ForecastItem::query()->create([
            'user_id' => $user->id,
            'space_id' => $spaceId,
            'account_id' => $accountId,
            'name' => $this->name($name, ''),
            'kind' => $frequency === ForecastFrequency::Once ? ForecastItemKind::OneOff : ForecastItemKind::Recurring,
            'frequency' => $frequency,
            'anchor_date' => $anchor,
            'source' => ForecastItemSource::Manual,
            'confidence' => ForecastConfidence::High,
            'date_window_days' => self::MANUAL_WINDOW_DAYS,
            'amount_tolerance' => (int) round(abs($amount) * self::MANUAL_TOLERANCE_SHARE),
        ]);

        $this->addVersion($item, $anchor, $amount);

        return $item;
    }

    private function addVersion(ForecastItem $item, string $effectiveFrom, int $amount): void
    {
        ForecastItemVersion::query()->create([
            'forecast_item_id' => $item->id,
            'effective_from' => $effectiveFrom,
            'amount' => $amount,
        ]);
    }

    private function name(?string $name, string $fallback): string
    {
        $name = trim((string) $name);

        return mb_substr($name !== '' ? $name : $fallback, 0, 255);
    }

    /**
     * The schedule's first date: the suggestion's next one, moved back a
     * whole number of periods to the first charge, and further to a month
     * long enough for its day, so a schedule on the 31st does not start on a
     * 30th and stay there.
     *
     * @param  Collection<string, Transaction>  $samples
     */
    private function anchorBefore(ForecastCandidate $candidate, Collection $samples): string
    {
        $step = $candidate->frequency->months();
        $first = $samples->get($candidate->transactionIds[0])?->transaction_date->toDateString() ?? $candidate->lastChargedOn;

        if ($step === null) {
            return $candidate->nextDate;
        }

        $from = Carbon::parse($first)->subDays(self::EARLY_DAYS)->toDateString();
        $periods = 0;

        while (ForecastCalendar::addMonths($candidate->nextDate, -($periods + 1) * $step) >= $from) {
            $periods++;
        }

        return $this->onAnchorDay($candidate, $periods, $step);
    }

    private function onAnchorDay(ForecastCandidate $candidate, int $periods, int $step): string
    {
        // Twelve periods back always reach a month with 31 days, or the same month.
        for ($extra = 0; $extra < 12; $extra++) {
            $date = ForecastCalendar::addMonths($candidate->nextDate, -($periods + $extra) * $step);

            if (ForecastCalendar::parse($date)[2] >= $candidate->anchorDay) {
                return $date;
            }
        }

        return ForecastCalendar::addMonths($candidate->nextDate, -$periods * $step);
    }

    /**
     * Pairs each charge with its nearest occurrence. Charges of merged
     * suggestions may share one; a charge another line already paid stays
     * with it.
     *
     * @param  Collection<string, Transaction>  $charges
     */
    private function pairCharges(ForecastItem $item, Collection $charges): void
    {
        if ($charges->isEmpty()) {
            return;
        }

        $dates = $charges->map(fn (Transaction $charge): string => $charge->transaction_date->toDateString());
        $occurrences = $this->expander->expand(
            [ExpandableItem::fromModel($item)],
            Carbon::parse($dates->min())->subDays(self::PAIRING_DAYS)->toDateString(),
            Carbon::parse($dates->max())->addDays(self::PAIRING_DAYS)->toDateString(),
        );
        $paired = ForecastMatch::query()->whereIn('transaction_id', $charges->keys())->pluck('transaction_id')->flip();

        foreach ($charges as $charge) {
            $occurrence = $this->nearest($occurrences, $dates[$charge->id]);

            if ($occurrence !== null && ! $paired->has($charge->id)) {
                ForecastMatch::query()->create([
                    'forecast_item_id' => $item->id,
                    'occurrence_date' => $occurrence->occurrenceDate,
                    'transaction_id' => $charge->id,
                    'matched_by' => ForecastMatchedBy::User,
                    'deviation' => $charge->amount - $occurrence->amount,
                ]);
            }
        }
    }

    /**
     * @param  list<Occurrence>  $occurrences
     */
    private function nearest(array $occurrences, string $date): ?Occurrence
    {
        $day = Carbon::parse($date);

        return collect($occurrences)->sortBy(fn (Occurrence $occurrence): int => (int) $day->diffInDays(Carbon::parse($occurrence->date), true))->first();
    }

    /**
     * The 1st of `$month` the next time it comes, or `$today` when it is the
     * month in progress.
     */
    private function nextDateIn(string $today, int $month): string
    {
        [$year, $currentMonth] = ForecastCalendar::parse($today);

        return match (true) {
            $month === $currentMonth => $today,
            $month > $currentMonth => ForecastCalendar::format($year, $month, 1),
            default => ForecastCalendar::format($year + 1, $month, 1),
        };
    }
}
