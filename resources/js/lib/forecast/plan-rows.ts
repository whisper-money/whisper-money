import {
    type ForecastEstimate,
    type ForecastGroup,
    type ForecastLine,
    type ForecastPlan,
    type ForecastTransferSuggestion,
} from '@/types/forecast';

/**
 * The rows of the forecast plan table, as a tree TanStack Table expands
 * through `subRows`: a section heading, then each parent category with its
 * concrete lines, its estimated rest and the "add something concrete" link
 * under it, then the transfers (and the one the warning suggests), and the
 * minimum and closing balance rows.
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
          type: 'add';
          id: string;
          section: 'income' | 'expenses';
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

function groupRow(
    group: ForecastGroup,
    section: 'income' | 'expenses',
): PlanRow {
    const subRows: PlanRow[] = group.items.map((line) => ({
        type: 'line',
        id: `line:${line.id}`,
        line,
    }));

    if (group.estimate) {
        subRows.push({
            type: 'estimate',
            id: `estimate:${group.key}`,
            estimate: group.estimate,
            group,
        });
    }

    subRows.push({ type: 'add', id: `add:${group.key}`, section, group });

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

    return {
        real: lines - (group.estimate?.current?.spent ?? 0),
        pending: group.items.some((line) => line.current.status === 'pending'),
    };
}

/** The ids of every expandable row of the plan. */
export function groupRowIds(rows: PlanRow[]): string[] {
    return rows.filter((row) => row.type === 'group').map((row) => row.id);
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
