import { type MonthlyEstimateKind } from '@/lib/forecast/types';
import {
    type ForecastEstimate,
    type ForecastGroup,
    type ForecastLine,
    type ForecastPlan,
    type ForecastTransferSuggestion,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';

/** A group's name: its category's, or "Other income" / "Other spending". */
export function groupName(
    group: Pick<ForecastGroup, 'name' | 'section'>,
): string {
    if (group.name !== null) {
        return group.name;
    }

    return group.section === 'income'
        ? __('Other income')
        : __('Other spending');
}

/**
 * The rows of the forecast plan table, as a tree TanStack Table expands
 * through `subRows`: the income section, then the expenses one, each with
 * a row per parent category ("Other income" / "Other spending" last), then
 * the transfers (and the one the warning suggests), and the minimum and
 * closing balance rows. A category is one figure a month, its estimate;
 * only one that holds lines the user added (a yearly payment, a one-off)
 * opens, into those lines and its estimate.
 */
export type PlanSection = 'income' | 'expenses' | 'transfers';

export type PlanRow =
    | { type: 'section'; id: string; section: PlanSection }
    | {
          type: 'group';
          id: string;
          section: 'income' | 'expenses';
          group: ForecastGroup;
          subRows: PlanRow[];
      }
    | { type: 'line'; id: string; line: ForecastLine }
    | {
          type: 'estimate';
          id: string;
          estimate: ForecastEstimate;
          group: ForecastGroup;
      }
    | {
          type: 'suggestion';
          id: string;
          suggestion: ForecastTransferSuggestion;
      }
    | { type: 'summary'; id: string; summary: 'minimum' | 'close' };

/** What a group adds up to in the month in progress. */
export interface GroupCurrentTotal {
    real: number;
    /** Whether a line of the group is still waiting for its transaction. */
    pending: boolean;
}

/** The row id of a parent category's group. */
export function groupId(section: 'income' | 'expenses', key: string): string {
    return `group:${section}:${key}`;
}

/** Whether a category opens: only into the lines the user added to it. */
export function opensIntoLines(group: Pick<ForecastGroup, 'items'>): boolean {
    return group.items.length > 0;
}

function groupRow(
    group: ForecastGroup,
    section: 'income' | 'expenses',
): PlanRow {
    const subRows: PlanRow[] = group.items.map((line) => ({
        type: 'line',
        id: `line:${line.id}`,
        line,
    }));

    if (group.estimate && opensIntoLines(group)) {
        subRows.push({
            type: 'estimate',
            id: `estimate:${group.key}`,
            estimate: group.estimate,
            group,
        });
    }

    return {
        type: 'group',
        id: groupId(section, group.key),
        section,
        group,
        subRows,
    };
}

export function buildPlanRows(
    plan: ForecastPlan,
    suggestion: ForecastTransferSuggestion | null = null,
): PlanRow[] {
    const rows: PlanRow[] = [];

    if (plan.income.length > 0) {
        rows.push({ type: 'section', id: 'section:income', section: 'income' });
        rows.push(...plan.income.map((group) => groupRow(group, 'income')));
    }

    rows.push({
        type: 'section',
        id: 'section:expenses',
        section: 'expenses',
    });
    rows.push(...plan.expenses.map((group) => groupRow(group, 'expenses')));

    if (plan.transfers.length > 0 || suggestion !== null) {
        rows.push({
            type: 'section',
            id: 'section:transfers',
            section: 'transfers',
        });
        rows.push(
            ...plan.transfers.map(
                (line): PlanRow => ({
                    type: 'line',
                    id: `line:${line.id}`,
                    line,
                }),
            ),
        );

        if (suggestion !== null) {
            rows.push({
                type: 'suggestion',
                id: 'suggestion:transfer',
                suggestion,
            });
        }
    }

    rows.push({ type: 'summary', id: 'summary:minimum', summary: 'minimum' });
    rows.push({ type: 'summary', id: 'summary:close', summary: 'close' });

    return rows;
}

/** A group's planned total in a future month, or null when nothing is planned. */
export function groupMonthTotal(
    group: ForecastGroup,
    month: string,
): number | null {
    const amounts = [
        ...group.items.map((line) => line.amounts[month]),
        group.estimate?.amounts[month],
    ].filter((amount): amount is number => amount !== undefined);

    return amounts.length === 0
        ? null
        : amounts.reduce((sum, amount) => sum + amount, 0);
}

export function groupCurrentTotal(group: ForecastGroup): GroupCurrentTotal {
    const lines = group.items.reduce(
        (sum, line) => sum + (line.current.real ?? 0),
        0,
    );

    const spent = group.estimate?.current?.spent ?? 0;

    return {
        real: lines + (group.estimate?.kind === 'income' ? spent : -spent),
        pending: group.items.some((line) => line.current.status === 'pending'),
    };
}

/** The ids of every row of the plan that opens. */
export function groupRowIds(rows: PlanRow[]): string[] {
    return rows
        .filter((row) => row.type === 'group' && row.subRows.length > 0)
        .map((row) => row.id);
}

/**
 * The groups open on the first render: those holding a line that is not
 * monthly in the problem month, which is what explains the dip.
 */
export function initiallyExpandedGroups(
    plan: ForecastPlan,
    alertMonth: string | null,
): string[] {
    if (alertMonth === null) {
        return [];
    }

    const opens = (group: ForecastGroup) =>
        group.items.some(
            (line) =>
                line.kind !== 'monthly' &&
                line.amounts[alertMonth] !== undefined,
        );

    return (['income', 'expenses'] as const).flatMap((section) =>
        plan[section].filter(opens).map((group) => groupId(section, group.key)),
    );
}

/**
 * How far a category's figure may land from its estimate: 40% of a spend,
 * 10% of an income, as the projection's band (lib/forecast/project.ts).
 */
export function estimateBand(
    kind: MonthlyEstimateKind,
    amount: number,
): number {
    const magnitude = Math.abs(amount);

    return kind === 'income'
        ? Math.floor(magnitude / 10)
        : Math.floor((magnitude * 2) / 5);
}
