import { addMonths } from './calendar';
import type {
    ExpandableForecastItem,
    ForecastFrequency,
    ForecastItemOverride,
    ForecastItemVersion,
    ForecastOccurrence,
} from './types';

/**
 * Port of app/Services/Forecast/OccurrenceExpander.php: turns forecast lines
 * into the dated occurrences that land between `from` and `to`, both
 * inclusive. tests/Fixtures/forecast-parity.json keeps the two in step.
 */

const FREQUENCY_MONTHS: Record<ForecastFrequency, number | null> = {
    monthly: 1,
    bimonthly: 2,
    quarterly: 3,
    yearly: 12,
    once: null,
};

function compare(a: string, b: string): number {
    if (a === b) {
        return 0;
    }

    return a < b ? -1 : 1;
}

function compareOccurrences(
    a: ForecastOccurrence,
    b: ForecastOccurrence,
): number {
    return (
        compare(a.date, b.date) ||
        compare(a.itemId, b.itemId) ||
        compare(a.occurrenceDate, b.occurrenceDate)
    );
}

/** How far to walk the schedule: past `to` when an override moves a later occurrence back. */
function lastScheduledDate(item: ExpandableForecastItem, to: string): string {
    let last = to;

    for (const override of item.overrides ?? []) {
        if (override.movedTo != null && override.occurrenceDate > last) {
            last = override.occurrenceDate;
        }
    }

    return item.endsOn != null && item.endsOn < last ? item.endsOn : last;
}

function scheduledDates(item: ExpandableForecastItem, until: string): string[] {
    const step = FREQUENCY_MONTHS[item.frequency];

    if (step === null) {
        return item.anchorDate <= until ? [item.anchorDate] : [];
    }

    const dates: string[] = [];
    for (
        let period = 0, date = item.anchorDate;
        date <= until;
        period++, date = addMonths(item.anchorDate, period * step)
    ) {
        dates.push(date);
    }

    return dates;
}

/** The latest version in force on the date; before the first, the first. */
function amountOn(versions: ForecastItemVersion[], date: string): number {
    let amount = versions[0].amount;

    for (const version of versions) {
        if (version.effectiveFrom <= date) {
            amount = version.amount;
        }
    }

    return amount;
}

function occurrenceOn(
    item: ExpandableForecastItem,
    versions: ForecastItemVersion[],
    scheduled: string,
    override: ForecastItemOverride | undefined,
): ForecastOccurrence | null {
    if (override?.skipped) {
        return null;
    }

    return {
        itemId: item.id,
        accountId: item.accountId,
        destinationAccountId: item.destinationAccountId ?? null,
        date: override?.movedTo ?? scheduled,
        occurrenceDate: scheduled,
        amount: override?.amount ?? amountOn(versions, scheduled),
    };
}

function expandItem(
    item: ExpandableForecastItem,
    from: string,
    to: string,
): ForecastOccurrence[] {
    if (item.versions.length === 0) {
        return [];
    }

    const versions = [...item.versions].sort((a, b) =>
        compare(a.effectiveFrom, b.effectiveFrom),
    );
    const overrides = new Map(
        (item.overrides ?? []).map((override) => [
            override.occurrenceDate,
            override,
        ]),
    );

    return scheduledDates(item, lastScheduledDate(item, to))
        .map((scheduled) =>
            occurrenceOn(item, versions, scheduled, overrides.get(scheduled)),
        )
        .filter(
            (occurrence): occurrence is ForecastOccurrence =>
                occurrence !== null &&
                occurrence.date >= from &&
                occurrence.date <= to,
        );
}

/** Every occurrence of the lines between `from` and `to`, sorted by date, then line id. */
export function expandOccurrences(
    items: ExpandableForecastItem[],
    from: string,
    to: string,
): ForecastOccurrence[] {
    return items
        .flatMap((item) => expandItem(item, from, to))
        .sort(compareOccurrences);
}
