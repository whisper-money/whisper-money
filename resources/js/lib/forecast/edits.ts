import {
    horizonEnd,
    monthKey,
    monthStart,
    onDay,
    previousDay,
} from '@/lib/forecast/calendar';
import { expandOccurrences } from '@/lib/forecast/expand-occurrences';
import { projectForecast } from '@/lib/forecast/project';
import {
    type ExpandableForecastItem,
    type ForecastFrequency,
    type ForecastItemVersion,
    type ForecastOccurrence,
    type ProjectedMonth,
    type ProjectionInput,
} from '@/lib/forecast/types';
import {
    type ForecastAlert,
    type ForecastData,
    type ForecastGroup,
    type ForecastKpis,
    type ForecastLine,
    type ForecastPlan,
} from '@/types/forecast';

/**
 * The edits the forecast page makes, applied on the client so the table,
 * the chart and the warnings answer at once. Each mirrors what the server
 * stores (app/Services/Forecast/ForecastItemWriter.php and the estimate
 * adjustments), and the server's answer replaces it when it lands.
 */

export type LineEditScope = 'occurrence' | 'from_here';

export interface LineEdit {
    scope: LineEditScope;
    occurrenceDate: string;
    /** Signed from the line's account; null keeps it. */
    amount: number | null;
    frequency: ForecastFrequency | null;
    day: number | null;
}

export type EstimateScope = 'month' | 'from_here';

/** The amount of the latest version in force on the date, else the first one. */
export function amountOn(
    versions: ForecastItemVersion[],
    date: string,
): number | null {
    const sorted = [...versions].sort((a, b) =>
        a.effectiveFrom.localeCompare(b.effectiveFrom),
    );
    const inForce = sorted.filter((version) => version.effectiveFrom <= date);

    return (inForce.at(-1) ?? sorted[0])?.amount ?? null;
}

/** A new amount from `effectiveFrom` on, replacing every later version. */
function withVersion(
    definition: ExpandableForecastItem,
    effectiveFrom: string,
    amount: number,
): ExpandableForecastItem {
    const earlier = definition.versions.filter(
        (version) => version.effectiveFrom < effectiveFrom,
    );

    return {
        ...definition,
        versions:
            amountOn(earlier, effectiveFrom) === amount
                ? earlier
                : [...earlier, { effectiveFrom, amount }],
    };
}

function editOccurrence(
    definition: ExpandableForecastItem,
    edit: LineEdit,
): ExpandableForecastItem {
    const overrides = definition.overrides ?? [];
    const existing = overrides.find(
        (override) => override.occurrenceDate === edit.occurrenceDate,
    );
    const movedTo =
        edit.day === null
            ? (existing?.movedTo ?? null)
            : onDay(edit.occurrenceDate, edit.day);

    return {
        ...definition,
        overrides: [
            ...overrides.filter((override) => override !== existing),
            {
                occurrenceDate: edit.occurrenceDate,
                amount: edit.amount ?? existing?.amount ?? null,
                movedTo: movedTo === edit.occurrenceDate ? null : movedTo,
                skipped: false,
            },
        ],
    };
}

/**
 * A new schedule from `date` on: in place when nothing of the line was paid
 * or left to come before it, else the line ends the day before and carries
 * on as a new one.
 */
function reschedule(
    definition: ExpandableForecastItem,
    date: string,
    anchorDate: string,
    frequency: ForecastFrequency,
    context: { today: string; paidBefore: boolean },
): ExpandableForecastItem[] {
    const from = monthStart(context.today);
    const kept = (definition.overrides ?? []).filter(
        (override) => override.occurrenceDate < date,
    );
    const hasEarlier =
        context.paidBefore ||
        (from < date &&
            expandOccurrences([definition], from, previousDay(date)).length >
                0);

    if (!hasEarlier) {
        return [{ ...definition, anchorDate, frequency, overrides: kept }];
    }

    return [
        { ...definition, endsOn: previousDay(date), overrides: kept },
        {
            ...definition,
            id: `${definition.id}:next`,
            anchorDate,
            frequency,
            versions: [
                {
                    effectiveFrom: anchorDate,
                    amount: amountOn(definition.versions, date) ?? 0,
                },
            ],
            overrides: [],
        },
    ];
}

/**
 * What a line becomes after an edit: itself, or itself and its successor.
 * `paidBefore` says whether a payment was matched to it before the edited
 * occurrence, which keeps its schedule from moving under that payment.
 */
export function editedDefinitions(
    definition: ExpandableForecastItem,
    edit: LineEdit,
    today: string,
    paidBefore = false,
): ExpandableForecastItem[] {
    if (edit.scope === 'occurrence') {
        return [editOccurrence(definition, edit)];
    }

    const anchorDate =
        edit.day === null
            ? edit.occurrenceDate
            : onDay(edit.occurrenceDate, edit.day);
    const frequency = edit.frequency ?? definition.frequency;
    const definitions =
        frequency !== definition.frequency || anchorDate !== edit.occurrenceDate
            ? reschedule(
                  definition,
                  edit.occurrenceDate,
                  anchorDate,
                  frequency,
                  { today, paidBefore },
              )
            : [definition];
    const target = definitions.length - 1;

    if (edit.amount !== null) {
        definitions[target] = withVersion(
            definitions[target],
            anchorDate,
            edit.amount,
        );
    }

    return definitions;
}

function compareOccurrences(
    a: ForecastOccurrence,
    b: ForecastOccurrence,
): number {
    return (
        a.date.localeCompare(b.date) ||
        a.itemId.localeCompare(b.itemId) ||
        a.occurrenceDate.localeCompare(b.occurrenceDate)
    );
}

/**
 * The input with a line's occurrences replaced by those of `definitions`
 * (none removes it; a new line has an id of its own).
 */
export function withLine(
    input: ProjectionInput,
    itemId: string,
    definitions: ExpandableForecastItem[],
): ProjectionInput {
    const ids = new Set([itemId, ...definitions.map((line) => line.id)]);
    const added = expandOccurrences(
        definitions,
        input.startDate,
        horizonEnd(input.startDate, input.horizonMonths),
    );

    return {
        ...input,
        occurrences: [
            ...input.occurrences.filter(
                (occurrence) => !ids.has(occurrence.itemId),
            ),
            ...added,
        ].sort(compareOccurrences),
    };
}

/** The input with the estimated spend of an account moved by `deltas` (by month). */
export function withEstimateDeltas(
    input: ProjectionInput,
    accountId: string,
    deltas: Record<string, number>,
): ProjectionInput {
    const estimates = [...(input.estimates ?? [])];

    for (const [month, delta] of Object.entries(deltas)) {
        const index = estimates.findIndex(
            (estimate) =>
                estimate.accountId === accountId && estimate.month === month,
        );
        const current = index === -1 ? 0 : estimates[index].amount;
        const next = { accountId, month, amount: Math.max(current + delta, 0) };

        if (index === -1) {
            estimates.push(next);
        } else {
            estimates[index] = next;
        }
    }

    return { ...input, estimates };
}

/**
 * How much the estimated rest of a group changes in each month an
 * adjustment covers: what is left to spend in the month in progress, the
 * whole figure in the months after it.
 */
export function estimateDeltas(
    group: ForecastGroup,
    amount: number,
    month: string,
    scope: EstimateScope,
    months: string[],
): Record<string, number> {
    const estimate = group.estimate;
    const deltas: Record<string, number> = {};
    const covered = months.filter((candidate) =>
        scope === 'month' ? candidate === month : candidate >= month,
    );

    for (const candidate of covered) {
        if (candidate === months[0]) {
            const spent = estimate?.current?.spent ?? 0;
            deltas[candidate] =
                Math.max(amount - spent, 0) - (estimate?.current?.left ?? 0);
        } else {
            deltas[candidate] = amount + (estimate?.amounts[candidate] ?? 0);
        }
    }

    return deltas;
}

/** The input with an account's wanted minimum and yearly rate changed. */
export function withAccountSettings(
    input: ProjectionInput,
    accountId: string,
    settings: { minimumBalance: number | null; annualRateBps: number | null },
): ProjectionInput {
    return {
        ...input,
        accounts: input.accounts.map((account) =>
            account.id === accountId ? { ...account, ...settings } : account,
        ),
    };
}

/** The projected months of one account. */
export function accountMonths(
    input: ProjectionInput,
    accountId: string,
): ProjectedMonth[] {
    return (
        projectForecast(input).accounts.find(
            (account) => account.accountId === accountId,
        )?.months ?? []
    );
}

/** Mirrors ForecastAlertEvaluator::evaluate(). */
export function evaluateAlert(
    months: ProjectedMonth[],
    threshold: number | null,
): Pick<ForecastAlert, 'level' | 'month' | 'min' | 'minDate' | 'softMonths'> {
    const read = threshold === null ? [] : months;
    const isStrong = (month: ProjectedMonth) => month.min < (threshold ?? 0);
    const strong = read
        .filter(isStrong)
        .reduce<ProjectedMonth | null>(
            (lowest, month) =>
                lowest === null || month.min < lowest.min ? month : lowest,
            null,
        );
    const softMonths = read
        .filter(
            (month) =>
                !isStrong(month) && month.pessimisticMin < (threshold ?? 0),
        )
        .map((month) => month.month);

    return {
        level: strong ? 'strong' : softMonths.length > 0 ? 'soft' : 'ok',
        month: strong?.month ?? null,
        min: strong?.min ?? null,
        minDate: strong?.minDate ?? null,
        softMonths,
    };
}

/** Mirrors ForecastPresenter::kpis(). */
export function kpisOf(
    months: ProjectedMonth[],
    balanceToday: number,
): ForecastKpis {
    const last = months[months.length - 1];
    const lowest = months.reduce((low, month) =>
        month.min < low.min ? month : low,
    );
    const expenses = months.reduce((sum, month) => sum + month.expenses, 0);
    const estimated = months.reduce((sum, month) => sum + month.estimated, 0);

    return {
        balanceToday,
        end: { month: last.month, close: last.close, band: last.band },
        lowest: {
            month: lowest.month,
            min: lowest.min,
            minDate: lowest.minDate,
        },
        concreteShare:
            expenses > 0
                ? Math.round((100 * (expenses - estimated)) / expenses)
                : null,
    };
}

/** The threshold the warnings read against (ForecastAlertEvaluator::threshold()). */
export function alertThreshold(
    type: string,
    minimumBalance: number | null,
): number | null {
    if (minimumBalance !== null) {
        return minimumBalance;
    }

    return type === 'credit_card' || type === 'loan' ? null : 0;
}

function lineAmounts(
    line: ForecastLine,
    occurrences: ForecastOccurrence[],
    accountId: string,
    months: string[],
): Record<string, number> {
    const amounts: Record<string, number> = {};

    if (line.amounts[months[0]] !== undefined) {
        amounts[months[0]] = line.amounts[months[0]];
    }

    for (const occurrence of occurrences) {
        const month = monthKey(occurrence.date);

        if (
            (occurrence.itemId !== line.id &&
                occurrence.itemId !== `${line.id}:next`) ||
            month === months[0] ||
            !months.includes(month)
        ) {
            continue;
        }

        const signed =
            occurrence.accountId === accountId
                ? occurrence.amount
                : -occurrence.amount;
        amounts[month] = (amounts[month] ?? 0) + signed;
    }

    return amounts;
}

function replanLines(
    plan: ForecastPlan,
    changed: (line: ForecastLine) => ForecastLine,
): ForecastPlan {
    const regroup = (groups: ForecastGroup[]) =>
        groups.map((group) => ({ ...group, items: group.items.map(changed) }));

    return {
        income: regroup(plan.income),
        expenses: regroup(plan.expenses),
        transfers: plan.transfers.map(changed),
    };
}

/**
 * The page after an edit: the projection, the figures and the warnings
 * recomputed from the edited input, and the amounts of the lines it
 * touched. The rest stays until the server answers.
 */
export function recomputeForecast(
    forecast: ForecastData,
    input: ProjectionInput,
    changedItemIds: string[] = [],
): ForecastData {
    const months = accountMonths(input, forecast.account.id);

    if (months.length === 0) {
        return forecast;
    }

    const alert = evaluateAlert(months, forecast.account.alertThreshold);
    const visible = forecast.months
        .filter((column) => column.status !== 'locked')
        .map((column) => column.month);
    const changed = new Set(changedItemIds);

    return {
        ...forecast,
        projection: months,
        kpis: kpisOf(months, forecast.kpis.balanceToday),
        alert: {
            ...forecast.alert,
            ...alert,
            causes:
                alert.month === forecast.alert.month
                    ? forecast.alert.causes
                    : [],
            transfer: alert.level === 'strong' ? forecast.alert.transfer : null,
        },
        plan: replanLines(forecast.plan, (line) =>
            changed.has(line.id)
                ? {
                      ...line,
                      amounts: lineAmounts(
                          line,
                          input.occurrences,
                          forecast.account.id,
                          visible,
                      ),
                  }
                : line,
        ),
    };
}

/** The month whose lowest balance an edit moves, the lowest one after it first. */
export function impactOn(
    before: ProjectedMonth[],
    after: ProjectedMonth[],
): { month: string; before: number; after: number } | null {
    const moved = after
        .map((month, index) => ({
            month: month.month,
            before: before[index]?.min,
            after: month.min,
        }))
        .filter(
            (change) =>
                change.before !== undefined && change.before !== change.after,
        );

    if (moved.length === 0) {
        return null;
    }

    return moved.reduce((lowest, change) =>
        change.after < lowest.after ? change : lowest,
    );
}

/** The plan with a group's estimated rest moved by `deltas`, as the table shows it. */
export function withEstimateAmounts(
    forecast: ForecastData,
    groupKey: string,
    deltas: Record<string, number>,
): ForecastData {
    const currentMonth = forecast.months[0]?.month;
    const adjust = (group: ForecastGroup): ForecastGroup => {
        if (group.key !== groupKey || group.estimate === null) {
            return group;
        }

        const amounts = { ...group.estimate.amounts };
        let current = group.estimate.current;

        for (const [month, delta] of Object.entries(deltas)) {
            if (month === currentMonth && current !== null) {
                current = {
                    ...current,
                    planned: current.planned + delta,
                    left: Math.max(current.left + delta, 0),
                };
            } else if (month !== currentMonth) {
                amounts[month] = (amounts[month] ?? 0) - delta;
            }
        }

        return {
            ...group,
            estimate: {
                ...group.estimate,
                basis: 'adjustment',
                amounts,
                current,
            },
        };
    };

    return {
        ...forecast,
        plan: {
            ...forecast.plan,
            expenses: forecast.plan.expenses.map(adjust),
        },
    };
}
