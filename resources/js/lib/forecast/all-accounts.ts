import { evaluateAlert, kpisOf } from '@/lib/forecast/edits';
import { isShort } from '@/lib/forecast/format';
import { opensIntoLines } from '@/lib/forecast/plan-rows';
import {
    type ForecastAccountEstimate,
    type ForecastAccountForecast,
    type ForecastAllData,
    type ForecastAllGroup,
    type ForecastData,
    type ForecastGroup,
    type ForecastLine,
} from '@/types/forecast';

/**
 * The rows of the "All accounts" plan: each section heading, then each
 * parent category (open or not) with the lines the user added and the
 * estimate of each account, the estimated growth, the transfers and their
 * effect on the total, and the close of each account and of the total. A
 * category estimated on a single account, with no line of its own, does
 * not open: its row is that estimate.
 */
export type AllPlanRow =
    | {
          type: 'section';
          id: string;
          section: 'income' | 'expenses' | 'transfers' | 'closes';
      }
    | {
          type: 'group';
          id: string;
          section: 'income' | 'expenses';
          group: ForecastAllGroup;
      }
    | { type: 'line'; id: string; line: ForecastLine }
    | {
          type: 'estimate';
          id: string;
          estimate: ForecastAccountEstimate;
          group: ForecastAllGroup;
      }
    | { type: 'growth'; id: string }
    | { type: 'effect'; id: string }
    | { type: 'account'; id: string; account: ForecastAccountForecast }
    | { type: 'total'; id: string };

export function allGroupId(section: 'income' | 'expenses', key: string) {
    return `group:${section}:${key}`;
}

/** The one estimate a category has, when it is on a single account. */
export function soleEstimate(
    group: ForecastAllGroup,
): ForecastAccountEstimate | null {
    return group.estimates.length === 1 ? group.estimates[0] : null;
}

/** Whether a category opens: into its lines, or into the estimate of each of its accounts. */
export function opensAllAccounts(group: ForecastAllGroup): boolean {
    return opensIntoLines(group) || group.estimates.length > 1;
}

function groupRows(
    group: ForecastAllGroup,
    section: 'income' | 'expenses',
    open: boolean,
): AllPlanRow[] {
    const id = allGroupId(section, group.key);
    const head: AllPlanRow = { type: 'group', id, section, group };

    if (!open || !opensAllAccounts(group)) {
        return [head];
    }

    return [
        head,
        ...group.items.map(
            (line): AllPlanRow => ({
                type: 'line',
                id: `line:${line.id}`,
                line,
            }),
        ),
        ...group.estimates.map(
            (estimate): AllPlanRow => ({
                type: 'estimate',
                id: `estimate:${group.key}:${estimate.accountId}`,
                estimate,
                group,
            }),
        ),
    ];
}

/** Whether any account the forecast includes grows by a yearly rate or follows a balance trend. */
export function hasGrowth(forecast: ForecastAllData): boolean {
    return forecast.accountForecasts.some(
        (account) =>
            (account.annualRateBps ?? 0) > 0 ||
            (account.trend?.monthly ?? null) !== null,
    );
}

export function buildAllPlanRows(
    forecast: ForecastAllData,
    isOpen: (id: string) => boolean,
): AllPlanRow[] {
    const { plan } = forecast;
    const rows: AllPlanRow[] = [];
    const sections = ['income', 'expenses'] as const;

    for (const section of sections) {
        if (section === 'expenses' || plan.income.length > 0) {
            rows.push({ type: 'section', id: `section:${section}`, section });
        }

        for (const group of plan[section]) {
            rows.push(
                ...groupRows(
                    group,
                    section,
                    isOpen(allGroupId(section, group.key)),
                ),
            );
        }
    }

    if (hasGrowth(forecast)) {
        rows.push({ type: 'growth', id: 'growth' });
    }

    if (plan.transfers.length > 0) {
        rows.push({
            type: 'section',
            id: 'section:transfers',
            section: 'transfers',
        });
        rows.push(
            ...plan.transfers.map(
                (line): AllPlanRow => ({
                    type: 'line',
                    id: `line:${line.id}`,
                    line,
                }),
            ),
        );
        rows.push({ type: 'effect', id: 'effect' });
    }

    rows.push({ type: 'section', id: 'section:closes', section: 'closes' });
    rows.push(
        ...forecast.accountForecasts.map(
            (account): AllPlanRow => ({
                type: 'account',
                id: `account:${account.id}`,
                account,
            }),
        ),
    );
    rows.push({ type: 'total', id: 'total' });

    return rows;
}

/** The ids of every parent category of the plan that opens. */
export function allGroupIds(forecast: ForecastAllData): string[] {
    return (['income', 'expenses'] as const).flatMap((section) =>
        forecast.plan[section]
            .filter(opensAllAccounts)
            .map((group) => allGroupId(section, group.key)),
    );
}

/** How many accounts fall below their threshold in a month. */
export function shortAccountCount(
    forecast: ForecastAllData,
    month: string,
): number {
    return forecast.accountForecasts.filter((account) => {
        const projected = account.projection.find(
            (candidate) => candidate.month === month,
        );

        return (
            projected !== undefined &&
            isShort(projected.min, account.alertThreshold)
        );
    }).length;
}

/** How many accounts fall below their threshold, by month. */
export function shortAccountsByMonth(
    forecast: ForecastAllData,
): Record<string, number> {
    return Object.fromEntries(
        forecast.projection.map((month) => [
            month.month,
            shortAccountCount(forecast, month.month),
        ]),
    );
}

/**
 * One account's estimate of a category as the estimate sheet edits it: a
 * category of a single account.
 */
export function accountEstimateGroup(
    group: ForecastAllGroup,
    estimate: ForecastAccountEstimate,
): ForecastGroup {
    return {
        ...group,
        items: group.items.filter(
            (line) => line.accountId === estimate.accountId,
        ),
        estimate,
    };
}

/**
 * The forecast of one account as the edit sheets read it, out of the "All
 * accounts" view: its own projection, currency and threshold. With no
 * account named (the header's "Add"), the one with the warning, else the
 * main checking account. The sheets
 * only read those; the rest is left empty, and the page waits for the
 * server's answer rather than recompute the total.
 */
export function accountSheetForecast(
    forecast: ForecastAllData,
    accountId: string | null | undefined,
): ForecastData {
    const account =
        forecast.accountForecasts.find(
            (candidate) => candidate.id === accountId,
        ) ??
        forecast.accountForecasts.find(
            (candidate) => candidate.id === forecast.alert.accountId,
        ) ??
        forecast.accountForecasts.find(
            (candidate) => candidate.type === 'checking',
        ) ??
        forecast.accountForecasts[0];
    const { projection, ...summary } = account;

    return {
        scope: 'account',
        today: forecast.today,
        horizon: forecast.horizon,
        isPro: forecast.isPro,
        accountChoices: forecast.accountChoices,
        currencyCode: account.currencyCode,
        account: summary,
        accounts: forecast.accounts,
        months: forecast.months,
        projection,
        past: [],
        alert: {
            ...evaluateAlert(projection, account.alertThreshold),
            causes: [],
            transfer: null,
        },
        lockedAlertCount: null,
        kpis: kpisOf(projection, projection[0]?.close ?? 0),
        plan: { income: [], expenses: [], transfers: [] },
    };
}
