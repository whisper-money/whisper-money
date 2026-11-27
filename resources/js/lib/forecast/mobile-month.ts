import { type ForecastEditTarget } from '@/components/forecast/forecast-editor';
import { accountEstimateGroup } from '@/lib/forecast/all-accounts';
import { groupId, groupName, opensIntoLines } from '@/lib/forecast/plan-rows';
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
 * one month at a time, as a list of rows: each parent category of income,
 * then each of spend ("Other income" / "Other spending" last in theirs),
 * then the transfers. A category is its estimate, which a tap adjusts;
 * one holding lines the user added (or, across every account, estimated
 * on several) opens into them.
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
    /** The day a line lands in the month; null for an estimate. */
    date: string | null;
    /** In the month in progress, whether a line was already paid or is still to come. */
    status: 'paid' | 'pending' | null;
    /** What tapping it opens, or null when the month cannot change. */
    target: ForecastEditTarget | null;
}

export interface MobileMonthGroup {
    id: string;
    section: 'income' | 'expenses' | 'transfers';
    name: string;
    /** What it is made of, for a row that opens; empty for one that does not. */
    hint: string;
    /** The category's only estimate, whose basis a row that does not open says. */
    estimate: ForecastEstimate | null;
    /** Whether the month is the one in progress, where the figure is what is still to come. */
    inProgress: boolean;
    total: number;
    currencyCode: string;
    approximate: boolean;
    entries: MobileMonthEntry[];
    /** Whether it opens into its entries; else a tap opens `target`. */
    opens: boolean;
    /** What tapping a row that does not open opens, or null when the month cannot change. */
    target: ForecastEditTarget | null;
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

/** A category's estimate in the month: what is left of it in the month in progress. */
function estimateAmount(
    estimate: ForecastEstimate,
    column: ForecastMonthColumn,
): number | undefined {
    if (column.status === 'current') {
        if (estimate.current === null) {
            return undefined;
        }

        return estimate.kind === 'income'
            ? estimate.current.left
            : -estimate.current.left;
    }

    return estimate.amounts[column.month];
}

interface EstimateSource {
    id: string;
    estimate: ForecastEstimate;
    /** The group the estimate sheet edits. */
    group: ForecastGroup;
    currencyCode: string;
    /** Set out of the "All accounts" view, where each account has its own estimate. */
    accountId?: string;
    accountName?: string;
}

function estimateTarget(
    source: EstimateSource,
    column: ForecastMonthColumn,
): ForecastEditTarget {
    return {
        kind: 'estimate',
        group: source.group,
        month: column.month,
        ...(source.accountId === undefined
            ? {}
            : { accountId: source.accountId }),
    };
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
            name: __('Estimate'),
            accountName: source.accountName,
            amount,
            currencyCode: source.currencyCode,
            approximate: true,
            date: null,
            status: null,
            target: estimateTarget(source, column),
        },
    ];
}

/** "Car insurance, Checking": the lines and the accounts a row that opens is made of. */
function contentsHint(entries: MobileMonthEntry[]): string {
    return entries
        .map((entry) =>
            entry.kind === 'estimate' ? entry.accountName : entry.name,
        )
        .filter((name): name is string => Boolean(name))
        .join(', ');
}

function sum(entries: MobileMonthEntry[]): number {
    return entries.reduce((total, entry) => total + entry.amount, 0);
}

/** A transfer reads as an arrow to (or from) the other account. */
function transferName(line: ForecastLine): string {
    const arrow = line.direction === 'in' ? '←' : '→';

    return `${arrow} ${line.counterpartyName ?? line.name}`;
}

function present<T>(value: T | null): value is T {
    return value !== null;
}

/** What a category of either view (an account, or all of them) has in common. */
type MonthGroupLike = Pick<ForecastGroup, 'key' | 'items' | 'name' | 'section'>;

/** How one view (an account, or all of them) reads its plan for a month. */
interface MonthSource<G extends MonthGroupLike> {
    plan: { income: G[]; expenses: G[]; transfers: ForecastLine[] };
    scope: Scope;
    currencyCode: string;
    estimatesOf: (group: G) => EstimateSource[];
    /** What a group adds up to, in `currencyCode`. */
    totalOf: (group: G, entries: MobileMonthEntry[]) => number;
    /** What the transfers change the balance by. */
    transfersTotal: (entries: MobileMonthEntry[]) => number;
}

function buildMonthGroups<G extends MonthGroupLike>(
    source: MonthSource<G>,
): MobileMonthGroup[] {
    const { plan, scope, currencyCode } = source;
    const categoryRow = (group: G): MobileMonthGroup | null => {
        const estimates = source.estimatesOf(group);
        const entries = [
            ...lineEntries(group.items, scope),
            ...estimates.flatMap((estimate) =>
                estimateEntry(estimate, scope.column),
            ),
        ];
        // Across every account a category may be estimated on several: it opens into them too.
        const opens = opensIntoLines(group) || estimates.length > 1;
        const [sole] = estimates;

        if (entries.length === 0) {
            return null;
        }

        return {
            id: groupId(group.section, group.key),
            section: group.section,
            name: groupName(group),
            hint: opens ? contentsHint(entries) : '',
            estimate: opens ? null : sole.estimate,
            inProgress: scope.column.status === 'current',
            total: source.totalOf(group, entries),
            currencyCode,
            approximate: entries.some((entry) => entry.approximate),
            entries,
            opens,
            target: opens ? null : estimateTarget(sole, scope.column),
        };
    };
    const transfers = lineEntries(plan.transfers, scope, transferName);
    const transfersRow: MobileMonthGroup | null =
        transfers.length === 0
            ? null
            : {
                  id: 'transfers',
                  section: 'transfers',
                  name: __('Transfers'),
                  hint: __(':names · not spending', {
                      names: transfers.map((entry) => entry.name).join(', '),
                  }),
                  estimate: null,
                  inProgress: scope.column.status === 'current',
                  total: source.transfersTotal(transfers),
                  currencyCode,
                  approximate: false,
                  entries: transfers,
                  opens: true,
                  target: null,
              };

    return [
        ...plan.income.map(categoryRow),
        ...plan.expenses.map(categoryRow),
        transfersRow,
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
    });
}
