<?php

namespace App\Services\Forecast;

use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\ItemOverride;
use App\Services\Forecast\Data\ItemVersion;
use App\Services\Forecast\Data\Occurrence;

/**
 * Turns forecast lines into the dated occurrences that land in a range.
 *
 * Each occurrence takes its date from the schedule (anchor date plus a whole
 * number of periods, clamped to the month's last day) and its amount from the
 * version in force on that date. An override keyed on that scheduled date can
 * change the amount, move it to another day, or skip it; a moved occurrence
 * lands in the range by its new date. Pure: the TypeScript port in
 * resources/js/lib/forecast/expand-occurrences.ts must give the same result,
 * which tests/Fixtures/forecast-parity.json checks.
 *
 * The forecast pages, jobs and MCP tool of the later phases call it; until
 * then only the tests do.
 *
 * @api
 */
final class OccurrenceExpander
{
    /**
     * @param  iterable<ExpandableItem>  $items
     * @return list<Occurrence> Sorted by date, then line id.
     */
    public function expand(iterable $items, string $from, string $to): array
    {
        $occurrences = [];

        foreach ($items as $item) {
            array_push($occurrences, ...$this->expandItem($item, $from, $to));
        }

        usort($occurrences, fn (Occurrence $a, Occurrence $b): int => strcmp($a->date, $b->date)
            ?: strcmp($a->itemId, $b->itemId)
            ?: strcmp($a->occurrenceDate, $b->occurrenceDate));

        return $occurrences;
    }

    /**
     * @return list<Occurrence>
     */
    private function expandItem(ExpandableItem $item, string $from, string $to): array
    {
        if ($item->versions === []) {
            return [];
        }

        $versions = $item->versions;
        usort($versions, fn (ItemVersion $a, ItemVersion $b): int => strcmp($a->effectiveFrom, $b->effectiveFrom));

        $overrides = [];
        foreach ($item->overrides as $override) {
            $overrides[$override->occurrenceDate] = $override;
        }

        $occurrences = [];
        foreach ($this->scheduledDates($item, $this->lastScheduledDate($item, $to)) as $scheduled) {
            $occurrence = $this->occurrenceOn($item, $versions, $scheduled, $overrides[$scheduled] ?? null);

            if ($occurrence !== null && $occurrence->date >= $from && $occurrence->date <= $to) {
                $occurrences[] = $occurrence;
            }
        }

        return $occurrences;
    }

    /**
     * How far the schedule has to be walked: to the end of the range, or past
     * it when an override moves a later occurrence back into the range.
     */
    private function lastScheduledDate(ExpandableItem $item, string $to): string
    {
        $last = $to;

        foreach ($item->overrides as $override) {
            if ($override->movedTo !== null && $override->occurrenceDate > $last) {
                $last = $override->occurrenceDate;
            }
        }

        return $item->endsOn !== null && $item->endsOn < $last ? $item->endsOn : $last;
    }

    /**
     * @return list<string>
     */
    private function scheduledDates(ExpandableItem $item, string $until): array
    {
        $step = $item->frequency->months();

        if ($step === null) {
            return $item->anchorDate <= $until ? [$item->anchorDate] : [];
        }

        $dates = [];
        for ($period = 0; ($date = ForecastCalendar::addMonths($item->anchorDate, $period * $step)) <= $until; $period++) {
            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * @param  list<ItemVersion>  $versions  Sorted by effective date.
     */
    private function occurrenceOn(ExpandableItem $item, array $versions, string $scheduled, ?ItemOverride $override): ?Occurrence
    {
        if ($override?->skipped) {
            return null;
        }

        return new Occurrence(
            $item->id,
            $item->accountId,
            $item->destinationAccountId,
            $override->movedTo ?? $scheduled,
            $scheduled,
            $override->amount ?? $this->amountOn($versions, $scheduled),
        );
    }

    /**
     * The amount of the latest version in force on the date. Before the first
     * version the first one applies, so a line always has an amount.
     *
     * @param  list<ItemVersion>  $versions  Sorted by effective date.
     */
    private function amountOn(array $versions, string $date): int
    {
        $amount = $versions[0]->amount;

        foreach ($versions as $version) {
            if ($version->effectiveFrom <= $date) {
                $amount = $version->amount;
            }
        }

        return $amount;
    }
}
