import { type ForecastEditTarget } from '@/components/forecast/forecast-editor';
import { accountEstimateGroup } from '@/lib/forecast/all-accounts';
import { dayOf } from '@/lib/forecast/format';
import { groupId } from '@/lib/forecast/plan-rows';
import {
    type ForecastAllData,
    type ForecastAllGroup,
    type ForecastData,
    type ForecastEstimate,
    type ForecastGroup,
    type ForecastLine,
    type ForecastLineKind,
    type ForecastMonthColumn,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';

/**
 * The mobile forecast's month card: instead of the plan's twelve columns,
 * one month at a time, as a list of rows (the income, each parent category
 * of spend, the transfers) that open into their concrete lines and their
 * estimated rest.
 */

export interface MobileMonthEntry {
    id: string;
    kind: ForecastLineKind | 'estimate';
    name: string;
    /** The account the entry is on, when the view shows several. */
    accountName?: string;
    /** Signed, in the minor units of `currencyCode`. */
    amount: number;
    currencyCode: string;
    approximate: boolean;
    /** The day a line lands in the month; null for an estimated rest. */
    date: string | null;
    /** In the month in progress, whether a line was already paid or is still to come. */
    status: 'paid' | 'pending' | null;
    /** What tapping it opens, or null when the month cannot change. */
    target: ForecastEditTarget | null;
}

export interface MobileMonthGroup {
    id: string;
    kind: 'income' | 'category' | 'transfers';
    name: string;
    hint: string;
    total: number;
    currencyCode: string;
    approximate: boolean;
    entries: MobileMonthEntry[];
    /** Where "+ Add something concrete" adds to, or null for no such link. */
    add: {
        section: 'income' | 'expenses';
        group: Pick<ForecastGroup, 'name' | 'categoryId'>;
    } | null;
}

interface Scope {
    column: ForecastMonthColumn;
    currencyOf: (accountId: string) => string;
    accountNameOf: (accountId: string) => string | undefined;
}

/** A line can be changed in a month it lands in, and in the month in progress only while it is still to come. */
function lineTarget(
    line: ForecastLine,
    column: ForecastMonthColumn,
): ForecastEditTarget | null {
    const editable =
        line.occurrenceDates[column.month] !== undefined &&
        (column.status !== 'current' || line.current.status === 'pending');

    return editable ? { kind: 'line', line, month: column.month } : null;
}

function currentStatus(
    line: ForecastLine,
    column: ForecastMonthColumn,
): MobileMonthEntry['status'] {
    if (column.status !== 'current') {
        return null;
    }

    if (line.current.status === 'matched') {
        return 'paid';
    }

    return line.current.status === 'pending' ? 'pending' : null;
}

function lineEntries(
    lines: ForecastLine[],
    scope: Scope,
    nameOf: (line: ForecastLine) => string = (line) => line.name,
): MobileMonthEntry[] {
    return lines.flatMap((line) => {
        const planned = line.amounts[scope.column.month];

        if (planned === undefined) {
            return [];
        }

        const status = currentStatus(line, scope.column);

        return [
            {
                id: `line:${line.id}`,
                kind: line.kind,
                name: nameOf(line),
                accountName: scope.accountNameOf(line.accountId),
                amount:
                    status === 'paid'
                        ? (line.current.real ?? planned)
                        : planned,
                currencyCode: scope.currencyOf(line.accountId),
                approximate: false,
                date: line.occurrenceDates[scope.column.month] ?? null,
                status,
                target: lineTarget(line, scope.column),
            },
        ];
    });
}

/** The estimated rest in the month: what is left of it in the month in progress. */
function estimateAmount(
    estimate: ForecastEstimate,
    column: ForecastMonthColumn,
): number | undefined {
    if (column.status === 'current') {
        return estimate.current === null ? undefined : -estimate.current.left;
    }

    return estimate.amounts[column.month];
}

interface EstimateSource {
    id: string;
    estimate: ForecastEstimate;
    /** The group the estimate sheet edits. */
    group: ForecastGroup;
    currencyCode: string;
    /** Set out of the "All accounts" view, where each account has its own rest. */
    accountId?: string;
    accountName?: string;
}

function estimateEntry(
    source: EstimateSource,
    column: ForecastMonthColumn,
): MobileMonthEntry[] {
    const amount = estimateAmount(source.estimate, column);

    if (amount === undefined) {
        return [];
    }

    return [
        {
            id: source.id,
            kind: 'estimate',
            name: __('Estimated rest'),
            accountName: source.accountName,
            amount,
            currencyCode: source.currencyCode,
            approximate: true,
            date: null,
            status: null,
            target: {
                kind: 'estimate',
                group: source.group,
                month: column.month,
                ...(source.accountId === undefined
                    ? {}
                    : { accountId: source.accountId }),
            },
        },
    ];
}

/** "2 concrete this month + estimated", or "Estimated" with no concrete line. */
export function monthHint(entries: MobileMonthEntry[]): string {
    const count = entries.filter((entry) => entry.kind !== 'estimate').length;
    const estimated = count < entries.length;

    if (count === 0 && estimated) {
        return __('Estimated');
    }

    const concrete =
        count === 1
            ? __('1 concrete this month')
            : __(':count concrete this month', { count });

    return estimated ? __(':concrete + estimated', { concrete }) : concrete;
}

function sum(entries: MobileMonthEntry[]): number {
    return entries.reduce((total, entry) => total + entry.amount, 0);
}

/** "Salary · day 28" for a single income, else how many there are. */
function incomeHint(entries: MobileMonthEntry[]): string {
    const [only] = entries;

    if (entries.length === 1 && only.date !== null) {
        return `${only.name} · ${__('day :day', { day: dayOf(only.date) })}`;
    }

    return monthHint(entries);
}

/** A transfer reads as an arrow to (or from) the other account. */
function transferName(line: ForecastLine): string {
    const arrow = line.direction === 'in' ? '←' : '→';

    return `${arrow} ${line.counterpartyName ?? line.name}`;
}

function present<T>(value: T | null): value is T {
    return value !== null;
}

/** How one view (an account, or all of them) reads its plan for a month. */
interface MonthSource<G extends { key: string; items: ForecastLine[] }> {
    plan: { income: G[]; expenses: G[]; transfers: ForecastLine[] };
    scope: Scope;
    currencyCode: string;
    estimatesOf: (group: G) => EstimateSource[];
    /** What a group adds up to, in `currencyCode`. */
    totalOf: (group: G, entries: MobileMonthEntry[]) => number;
    /** What the transfers change the balance by. */
    transfersTotal: (entries: MobileMonthEntry[]) => number;
    asGroup: (group: G) => Pick<ForecastGroup, 'name' | 'categoryId'>;
}

function buildMonthGroups<G extends { key: string; items: ForecastLine[] }>(
    source: MonthSource<G>,
): MobileMonthGroup[] {
    const { plan, scope, currencyCode } = source;
    const entriesOf = (group: G) => [
        ...lineEntries(group.items, scope),
        ...source
            .estimatesOf(group)
            .flatMap((estimate) => estimateEntry(estimate, scope.column)),
    ];
    const row = (
        kind: MobileMonthGroup['kind'],
        rest: Omit<MobileMonthGroup, 'kind' | 'currencyCode' | 'approximate'>,
    ): MobileMonthGroup | null =>
        rest.entries.length === 0
            ? null
            : {
                  kind,
                  currencyCode,
                  approximate: rest.entries.some((entry) => entry.approximate),
                  ...rest,
              };
    const income = plan.income.map((group) => ({
        group,
        entries: entriesOf(group),
    }));
    const incomeEntries = income.flatMap(({ entries }) => entries);
    const transfers = lineEntries(plan.transfers, scope, transferName);

    return [
        row('income', {
            id: 'income',
            name: __('Income'),
            hint: incomeHint(incomeEntries),
            total: income.reduce(
                (total, { group, entries }) =>
                    total + source.totalOf(group, entries),
                0,
            ),
            entries: incomeEntries,
            add: null,
        }),
        ...plan.expenses.map((group) => {
            const entries = entriesOf(group);
            const named = source.asGroup(group);

            return row('category', {
                id: groupId('expenses', group.key),
                name: named.name ?? __('Other spending'),
                hint: monthHint(entries),
                total: source.totalOf(group, entries),
                entries,
                add: { section: 'expenses', group: named },
            });
        }),
        row('transfers', {
            id: 'transfers',
            name: __('Transfers'),
            hint: __(':names · not spending', {
                names: transfers.map((entry) => entry.name).join(', '),
            }),
            total: source.transfersTotal(transfers),
            entries: transfers,
            add: null,
        }),
    ].filter(present);
}

/** The month's rows for one account. */
export function accountMonthGroups(
    forecast: ForecastData,
    column: ForecastMonthColumn,
): MobileMonthGroup[] {
    return buildMonthGroups<ForecastGroup>({
        plan: forecast.plan,
        scope: {
            column,
            currencyOf: () => forecast.currencyCode,
            accountNameOf: () => undefined,
        },
        currencyCode: forecast.currencyCode,
        estimatesOf: (group) =>
            group.estimate === null
                ? []
                : [
                      {
                          id: `estimate:${group.key}`,
                          estimate: group.estimate,
                          group,
                          currencyCode: forecast.currencyCode,
                      },
                  ],
        totalOf: (_, entries) => sum(entries),
        transfersTotal: sum,
        asGroup: (group) => group,
    });
}

/**
 * The month's rows across every account: each total in the user's
 * currency, each line and estimated rest in its account's own.
 */
export function allMonthGroups(
    forecast: ForecastAllData,
    column: ForecastMonthColumn,
): MobileMonthGroup[] {
    const accounts = new Map(
        forecast.accounts.map((account) => [account.id, account]),
    );
    const currencyOf = (id: string) =>
        accounts.get(id)?.currencyCode ?? forecast.currencyCode;

    return buildMonthGroups<ForecastAllGroup>({
        plan: forecast.plan,
        scope: {
            column,
            currencyOf,
            accountNameOf: (id) => accounts.get(id)?.name,
        },
        currencyCode: forecast.currencyCode,
        estimatesOf: (group) =>
            group.estimates.map((estimate) => ({
                id: `estimate:${group.key}:${estimate.accountId}`,
                estimate,
                group: accountEstimateGroup(group, estimate),
                currencyCode: currencyOf(estimate.accountId),
                accountId: estimate.accountId,
                accountName: accounts.get(estimate.accountId)?.name,
            })),
        totalOf: (group, entries) => group.totals[column.month] ?? sum(entries),
        transfersTotal: () => forecast.plan.transferEffect[column.month] ?? 0,
        asGroup: (group) => group,
    });
}
