<?php

namespace App\Services\Forecast;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Enums\ForecastItemSource;
use App\Models\ForecastItem;
use App\Models\ForecastItemOverride;
use App\Models\User;
use App\Services\Forecast\Data\DriftSuggestion;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\NewForecastLine;
use Illuminate\Support\Facades\DB;

/**
 * Every change the user makes to their forecast lines. A line's amount
 * lives in its versions, so "from this month on" adds a version, and "only
 * this month" stores an override of that one occurrence; nothing already
 * planned before the change moves. A new schedule (another repetition or
 * day) from a month on rewrites the line in place when nothing of it was
 * left to come before that month, and otherwise ends the line the day
 * before and carries on as a new one, so the months before keep theirs.
 *
 * Callers check the user may make the change (ForecastEditWindow).
 */
class ForecastItemWriter
{
    /**
     * How far from its amount a payment may stray and still pay a line the
     * user typed in, as a share of the amount ("±5 %").
     */
    private const DEFAULT_TOLERANCE = 0.05;

    public function __construct(
        private readonly OccurrenceExpander $expander,
    ) {}

    public function create(User $user, string $spaceId, NewForecastLine $line): ForecastItem
    {
        return DB::transaction(function () use ($user, $spaceId, $line): ForecastItem {
            $item = ForecastItem::query()->create([
                'user_id' => $user->id,
                'space_id' => $spaceId,
                'account_id' => $line->accountId,
                'destination_account_id' => $line->destinationAccountId,
                'destination_external' => $line->destinationAccountId === null && $line->destinationExternal,
                'category_id' => $line->categoryId,
                'name' => $line->name,
                'kind' => $line->frequency === ForecastFrequency::Once ? ForecastItemKind::OneOff : ForecastItemKind::Recurring,
                'frequency' => $line->frequency,
                'anchor_date' => $line->anchorDate,
                'ends_on' => $line->endsOn,
                'source' => $line->source,
                'confidence' => ForecastConfidence::High,
                'match_rules_json' => $line->matchRules ?? ($line->isTransfer() ? null : ForecastMatchRules::forName($line->name)),
                'amount_tolerance' => $line->amountTolerance ?? (int) round(abs($line->amount) * self::DEFAULT_TOLERANCE),
                'fingerprint' => $line->fingerprint,
            ]);
            $item->versions()->create(['effective_from' => $line->anchorDate, 'amount' => $line->amount]);

            return $item;
        });
    }

    /**
     * "Only this month": another amount, or another day, for one occurrence.
     */
    public function changeOccurrence(ForecastItem $item, string $occurrenceDate, ?int $amount, ?string $movedTo): void
    {
        $override = ForecastItemOverride::query()->firstOrNew(['forecast_item_id' => $item->id, 'occurrence_date' => $occurrenceDate]);
        $override->fill([
            'amount' => $amount ?? $override->amount,
            'moved_to' => $movedTo === $occurrenceDate ? null : ($movedTo ?? $override->moved_to),
            'skipped' => false,
        ])->save();
    }

    /**
     * "From this month on": the amount, the repetition or the day change for
     * the occurrence on `$occurrenceDate` and every one after it.
     */
    public function changeFromHere(User $user, ForecastItem $item, string $occurrenceDate, ?int $amount, ?ForecastFrequency $frequency, ?int $day): ForecastItem
    {
        return DB::transaction(function () use ($user, $item, $occurrenceDate, $amount, $frequency, $day): ForecastItem {
            $anchor = $day === null ? $occurrenceDate : self::onDay($occurrenceDate, $day);
            $frequency ??= $item->frequency;

            if ($frequency !== $item->frequency || $anchor !== $occurrenceDate) {
                $item = $this->reschedule($user, $item, $occurrenceDate, $anchor, $frequency);
            }

            if ($amount !== null && $amount !== $this->amountOn($item, $anchor)) {
                $item->versions()->updateOrCreate(['effective_from' => $anchor], ['amount' => $amount]);
            }

            return $item;
        });
    }

    /**
     * Updates a line to what its last payments were (ForecastDrift), from
     * the first occurrence still to come after them.
     */
    public function acceptDrift(User $user, ForecastItem $item, DriftSuggestion $suggestion): void
    {
        $item->versions()->updateOrCreate(['effective_from' => $this->driftStartsOn($user, $item, $suggestion)], ['amount' => $suggestion->amount]);
    }

    /**
     * The occurrence a drift update starts on: the first one scheduled after
     * the latest payment, and not before today.
     */
    public function driftStartsOn(User $user, ForecastItem $item, DriftSuggestion $suggestion): string
    {
        $today = $user->localToday()->toDateString();
        $afterPayment = $suggestion->lastPaidOn === null ? $today : date('Y-m-d', strtotime($suggestion->lastPaidOn.' +1 day'));
        $from = max($today, $afterPayment);
        $next = $this->expander->expand([ExpandableItem::fromModel($item)], $from, ForecastCalendar::horizonEnd($from, 13))[0] ?? null;

        return $next->occurrenceDate ?? $from;
    }

    /**
     * "Stop forecasting it": the line is archived, so it stops counting and
     * stops being matched, and its history stays.
     */
    public function archive(ForecastItem $item): void
    {
        $item->forceFill(['archived_at' => now()])->save();
    }

    private function reschedule(User $user, ForecastItem $item, string $occurrenceDate, string $anchor, ForecastFrequency $frequency): ForecastItem
    {
        $kind = $frequency === ForecastFrequency::Once ? ForecastItemKind::OneOff : ForecastItemKind::Recurring;

        if (! $this->hasOccurrencesBefore($user, $item, $occurrenceDate)) {
            $item->overrides()->where('occurrence_date', '>=', $occurrenceDate)->delete();
            $item->forceFill(['anchor_date' => $anchor, 'frequency' => $frequency, 'kind' => $kind])->save();

            return $item->refresh();
        }

        $amount = $this->amountOn($item, $occurrenceDate);
        $successor = $item->replicate(['archived_at']);
        $successor->forceFill(['anchor_date' => $anchor, 'frequency' => $frequency, 'kind' => $kind, 'ends_on' => $item->ends_on, 'source' => ForecastItemSource::Manual])->save();
        $successor->versions()->create(['effective_from' => $anchor, 'amount' => $amount]);

        $item->overrides()->where('occurrence_date', '>=', $occurrenceDate)->delete();
        $item->forceFill(['ends_on' => date('Y-m-d', strtotime($occurrenceDate.' -1 day'))])->save();

        return $successor;
    }

    /**
     * Whether the line has an occurrence between the start of the month in
     * progress and `$date`: one the new schedule must not take away.
     */
    private function hasOccurrencesBefore(User $user, ForecastItem $item, string $date): bool
    {
        $from = ForecastCalendar::monthStart($user->localToday()->toDateString());

        return $from < $date
            && $this->expander->expand([ExpandableItem::fromModel($item)], $from, date('Y-m-d', strtotime($date.' -1 day'))) !== [];
    }

    private function amountOn(ForecastItem $item, string $date): ?int
    {
        $item->load('versions');

        return $item->versions->filter(fn ($version): bool => $version->effective_from->toDateString() <= $date)->last()->amount
            ?? $item->versions->first()?->amount;
    }

    /**
     * The date in `$date`'s month on `$day`, or on its last day when shorter.
     */
    public static function onDay(string $date, int $day): string
    {
        [$year, $month] = ForecastCalendar::parse($date);

        return ForecastCalendar::format($year, $month, min($day, ForecastCalendar::daysInMonth($year, $month)));
    }
}
