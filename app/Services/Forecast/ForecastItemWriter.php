<?php

namespace App\Services\Forecast;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Enums\ForecastItemSource;
use App\Models\ForecastItem;
use App\Models\ForecastItemOverride;
use App\Models\User;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\NewForecastLine;
use Illuminate\Support\Facades\DB;

/**
 * Every change the user makes to their forecast lines. A line's amount
 * lives in its versions, so "from this month on" adds a version, and "only
 * this month" stores an override of that one occurrence; nothing already
 * planned before the change moves. A new schedule (another repetition or
 * day) from a month on rewrites the line in place when nothing of it was
 * paid or left to come before that month, and otherwise ends the line the
 * day before and carries on as a new one, so the months before keep theirs.
 *
 * "From this month on" with a new amount replaces every later version too:
 * the months after it take the amount the user just typed.
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
            $anchor = $day === null ? $occurrenceDate : ForecastCalendar::onDay($occurrenceDate, $day);
            $frequency ??= $item->frequency;

            if ($frequency !== $item->frequency || $anchor !== $occurrenceDate) {
                $item = $this->reschedule($user, $item, $occurrenceDate, $anchor, $frequency);
            }

            if ($amount !== null) {
                $item->versions()->where('effective_from', '>', $anchor)->delete();
            }

            if ($amount !== null && $amount !== $this->amountOn($item, $anchor)) {
                $item->versions()->updateOrCreate(['effective_from' => $anchor], ['amount' => $amount]);
            }

            return $item;
        });
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
        $item->forceFill(['ends_on' => ForecastCalendar::previousDay($occurrenceDate)])->save();

        return $successor;
    }

    /**
     * Whether the line has something before `$date` the new schedule must
     * not take away: a payment matched to it, or an occurrence still to come
     * since the month in progress started.
     */
    private function hasOccurrencesBefore(User $user, ForecastItem $item, string $date): bool
    {
        $from = ForecastCalendar::monthStart($user->localToday()->toDateString());

        if ($item->matches()->where('occurrence_date', '<', $date)->exists()) {
            return true;
        }

        return $from < $date
            && $this->expander->expand([ExpandableItem::fromModel($item)], $from, ForecastCalendar::previousDay($date)) !== [];
    }

    /**
     * The amount of the version in force on the date, else the first one.
     */
    public static function amountOn(ForecastItem $item, string $date): ?int
    {
        $versions = $item->versions()->get();

        return $versions->filter(fn ($version): bool => $version->effective_from->toDateString() <= $date)->last()->amount
            ?? $versions->first()?->amount;
    }
}
