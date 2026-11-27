import {
    type ExpandableForecastItem,
    type ProjectedMonth,
    type ProjectionInput,
} from '@/lib/forecast/types';

/**
 * The forecast page's payload, as App\Services\Forecast\ForecastPresenter
 * builds it. Money is in signed minor units of the account's currency, months
 * are `YYYY-MM`. Without Pro only the month in progress and the next one carry
 * figures: the later months are listed as `locked`, with nothing in them.
 */

export type ForecastHorizon = 3 | 6 | 12;

export type ForecastAlertLevel = 'strong' | 'soft' | 'ok';

export type ForecastMonthStatus = 'current' | 'future' | 'locked';

export type ForecastLineKind =
    | 'monthly'
    | 'bimonthly'
    | 'quarterly'
    | 'yearly'
    | 'once'
    | 'transfer';

/** `estimate`: a usual transfer between the user's accounts, read-only. */
export type ForecastLineSource =
    | 'detected'
    | 'manual'
    | 'suggested'
    | 'estimate';

/**
 * The line an account with only a balance history is projected along, from
 * its month-end balances of the last 12 complete months. `monthly` is null
 * when fewer than 4 of them had a balance: the account is then kept flat.
 */
export interface ForecastTrend {
    monthly: number | null;
    band: number | null;
    points: number;
}

export interface ForecastAccountTab {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
    /** Counted in the total but never warns nor lends. */
    projectionOnly: boolean;
    /** Null when the account is projected from its movements. */
    trend: ForecastTrend | null;
    hasAlert: boolean;
}

/** An account of the space, and whether the forecast covers it. */
export interface ForecastAccountChoice {
    id: string;
    name: string;
    type: string;
    included: boolean;
    projectionOnly: boolean;
}

export interface ForecastAccountSummary {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
    minimumBalance: number | null;
    /** Yearly growth of an investment or property, in basis points. */
    annualRateBps: number | null;
    /** Counted in the total but never warns nor lends: an investment, a pension, another kind of account. */
    projectionOnly: boolean;
    /** Null when the account is projected from its movements. */
    trend: ForecastTrend | null;
    /** The balance the warnings read against; null for an account that never warns (anything but checking and savings). */
    alertThreshold: number | null;
}

export interface ForecastMonthColumn {
    month: string;
    status: ForecastMonthStatus;
}

export interface ForecastPastMonth {
    month: string;
    income: number;
    expenses: number;
    /** Null before the account's balance history starts. */
    close: number | null;
    min: number | null;
}

export interface ForecastAlert {
    level: ForecastAlertLevel;
    month: string | null;
    min: number | null;
    minDate: string | null;
    softMonths: string[];
    causes: { name: string; amount: number }[];
    /** The transfer the strong warning offers, when another account can lend. */
    transfer: ForecastTransferSuggestion | null;
}

export interface ForecastTransferSuggestion {
    fromAccountId: string;
    /** Positive, in minor units. */
    amount: number;
    date: string;
    month: string;
}

/** A payment that paid the line, signed from the line's own account. */
export interface ForecastLinePayment {
    occurrenceDate: string;
    planned: number;
    real: number;
}

export interface ForecastKpis {
    balanceToday: number;
    end: { month: string; close: number; band: number };
    lowest: { month: string; min: number; minDate: string | null };
    /** Percentage of the spend that is concrete, or null with no spend. */
    concreteShare: number | null;
}

export interface ForecastCurrentCell {
    planned: number | null;
    real: number | null;
    /** The day the line is due this month, when it is. */
    date: string | null;
    status: 'matched' | 'pending' | 'none';
}

export interface ForecastLine {
    id: string;
    name: string;
    kind: ForecastLineKind;
    source: ForecastLineSource;
    anchorDate: string;
    direction: 'in' | 'out' | null;
    counterpartyName: string | null;
    amounts: Record<string, number>;
    current: ForecastCurrentCell;
    accountId: string;
    categoryId: string | null;
    /** The line as the client engine expands it, up to the last month shown. */
    definition: ExpandableForecastItem;
    /** The date the schedule gives the line's occurrence in each month shown. */
    occurrenceDates: Record<string, string>;
    /** What a transaction's description must contain to pay it, when the rule is that simple. */
    matchText: string | null;
    amountTolerance: number;
    dateWindowDays: number;
    payments: ForecastLinePayment[];
}

export interface ForecastEstimate {
    /** Income comes in, spend goes out. */
    kind: 'income' | 'expense';
    basis: 'median' | 'budget' | 'adjustment' | null;
    budgetAmount: number | null;
    /** Signed amounts by month (income positive, spend negative), the month in progress left out. */
    amounts: Record<string, number>;
    /** The month in progress, all positive: `spent` is what already went out (or came in, for income). */
    current: { spent: number; planned: number; left: number } | null;
}

export interface ForecastGroup {
    key: string;
    section: 'income' | 'expenses';
    categoryId: string | null;
    name: string | null;
    icon: string | null;
    color: string | null;
    items: ForecastLine[];
    estimate: ForecastEstimate | null;
}

export interface ForecastPlan {
    income: ForecastGroup[];
    expenses: ForecastGroup[];
    transfers: ForecastLine[];
}

interface ForecastBase {
    today: string;
    horizon: ForecastHorizon;
    isPro: boolean;
    /** Every account of the space, by name, with whether the forecast covers it. */
    accountChoices: ForecastAccountChoice[];
}

export interface ForecastEmpty extends ForecastBase {
    scope: 'account' | 'all';
    account: null;
    accounts: [];
}

export interface ForecastData extends ForecastBase {
    scope: 'account';
    currencyCode: string;
    account: ForecastAccountSummary;
    accounts: ForecastAccountTab[];
    months: ForecastMonthColumn[];
    /** The month in progress (from today on) and the visible future months. */
    projection: ProjectedMonth[];
    past: ForecastPastMonth[];
    alert: ForecastAlert;
    lockedAlertCount: number | null;
    kpis: ForecastKpis;
    plan: ForecastPlan;
}

/**
 * A yearly payment the history suggests, as
 * App\Services\Forecast\ForecastReviewPresenter sends it. `amount` is
 * signed from the account, in its minor units; `key` is what the review
 * sends back to add or dismiss it.
 */
export interface ForecastAnnualSuggestion {
    key: string;
    name: string;
    accountId: string;
    accountName: string | null;
    currencyCode: string;
    nextDate: string;
    amount: number;
    /** The charges it stands on, oldest first. */
    evidence: { date: string; amount: number }[];
}

export interface ForecastReviewAccount {
    id: string;
    name: string;
    currencyCode: string;
}

/**
 * One row of the review's first step: what a parent category (null for
 * "Other income" / "Other spending") is expected to move on one account
 * next month, positive, in the account's currency.
 */
export interface ForecastReviewEstimate {
    accountId: string;
    accountName: string | null;
    currencyCode: string;
    categoryId: string | null;
    name: string | null;
    kind: 'income' | 'expense';
    amount: number;
    basis: 'median' | 'budget' | 'adjustment';
    budgetAmount: number | null;
}

/** A usual transfer between two of the user's accounts next month, positive. */
export interface ForecastReviewTransfer {
    key: string;
    fromAccountName: string;
    toAccountName: string;
    amount: number;
    currencyCode: string;
}

/** How next month is estimated; the totals are in the user's currency. */
export interface ForecastReviewEstimates {
    /** `Y-m`. */
    month: string;
    currencyCode: string;
    income: ForecastReviewEstimate[];
    expenses: ForecastReviewEstimate[];
    transfers: ForecastReviewTransfer[];
    totals: { income: number; expenses: number; net: number };
    /** Currencies without a rate today, left out of the totals. */
    unconvertedCurrencies: string[];
    /** Budgets the forecast cannot stand on: their categories use the median. */
    unusableBudgets: string[];
}

/**
 * Too much money without a category over the last three complete months
 * (`from`–`to`, `Y-m-d`): how many transactions, and how much a month in
 * the user's currency.
 */
export interface ForecastReviewUncategorized {
    count: number;
    monthlyAmount: number;
    currencyCode: string;
    from: string;
    to: string;
}

export interface ForecastReview {
    /** The account reviewed; null for every included account. */
    accountId: string | null;
    /** Null while 20 % or less of the money has no category. */
    uncategorized: ForecastReviewUncategorized | null;
    /** Null without an account to estimate. */
    estimates: ForecastReviewEstimates | null;
    annual: ForecastAnnualSuggestion[];
    accounts: ForecastReviewAccount[];
}

export interface ForecastReviewPrompt {
    /** Whether the space has any forecast line: without one the review opens on its own. */
    hasLines: boolean;
}

/*
 * The "All accounts" view (`?account=all`), as
 * App\Services\Forecast\ForecastAllAccountsPresenter builds it. The total,
 * the KPIs and each category's totals are in the user's currency
 * (`currencyCode`); each line, estimated rest and account in its own.
 */

/** The estimated rest of one account under a parent category. */
export interface ForecastAccountEstimate extends ForecastEstimate {
    accountId: string;
}

/** A parent category summed across the accounts. */
export interface ForecastAllGroup extends Omit<ForecastGroup, 'estimate'> {
    estimates: ForecastAccountEstimate[];
    /** What the category adds up to by month, in the user's currency. */
    totals: Record<string, number>;
    /** What it really added up to in the month in progress so far. */
    current: { real: number; pending: boolean };
}

/** One account's own projection, in its currency. */
export interface ForecastAccountForecast extends ForecastAccountSummary {
    projection: ProjectedMonth[];
}

/**
 * `split`: an account runs short and what the others have above their
 * threshold covers it; `short`: it does not, so the total does not hold.
 */
export type ForecastAllAlertLevel = 'split' | 'short' | 'soft' | 'ok';

export interface ForecastAllAlert {
    level: ForecastAllAlertLevel;
    /** The account in the worst trouble. */
    accountId: string | null;
    month: string | null;
    min: number | null;
    minDate: string | null;
    softMonths: string[];
    transfer: ForecastTransferSuggestion | null;
}

export interface ForecastAllKpis {
    balanceToday: number;
    accountCount: number;
    end: { month: string; close: number; band: number };
    /** Income less expenses over the months shown. */
    saved: number;
    /** The estimated growth over the months shown. */
    growth: number;
    /** What the transfers between the accounts move over the months shown. */
    moved: number;
}

export interface ForecastAllPlan {
    income: ForecastAllGroup[];
    expenses: ForecastAllGroup[];
    /** Signed from the destination: the positive amount moved. */
    transfers: ForecastLine[];
    growth: Record<string, number>;
    /** What the transfers change the total by: zero unless one leaves the forecast. */
    transferEffect: Record<string, number>;
}

export interface ForecastAllData extends ForecastBase {
    scope: 'all';
    currencyCode: string;
    account: null;
    accounts: ForecastAccountTab[];
    months: ForecastMonthColumn[];
    /** The total of every account counted. */
    projection: ProjectedMonth[];
    /** Currencies with no rate today: their accounts are left out of the total. */
    unconvertedCurrencies: string[];
    past: ForecastPastMonth[];
    accountForecasts: ForecastAccountForecast[];
    alert: ForecastAllAlert;
    lockedAlertCount: number | null;
    kpis: ForecastAllKpis;
    plan: ForecastAllPlan;
}

export interface ForecastPageProps {
    forecast: ForecastData | ForecastAllData | ForecastEmpty;
    projectionInput: ProjectionInput | null;
    reviewPrompt: ForecastReviewPrompt;
    /** Suggestions not answered yet; deferred, so undefined at first. */
    newSuggestionCount?: number;
    /** Only sent when the page asks for it (`only: ['review']`). */
    review?: ForecastReview;
    /** The "What you pay every month" card; deferred, so undefined at first. */
    recurringCharges?: ForecastRecurringCharges;
}

/** A charge that repeats, on the "What you pay every month" card. */
export interface ForecastRecurringCharge {
    /** Names the charge across visits, for "I'm dropping it" and "Keep". */
    key: string;
    name: string;
    accountId: string;
    accountName: string | null;
    currencyCode: string;
    frequency: 'monthly' | 'bimonthly' | 'quarterly' | 'yearly';
    /** The median of its last three charges, positive, in minor units. */
    amount: number;
    /** What it costs a month and a year at its frequency. */
    monthly: number;
    yearly: number;
    /** `YYYY-MM-DD`. */
    lastChargedOn: string;
    /** False when its category is not spend the forecast counts. */
    canDrop: boolean;
    /**
     * Set once the user is dropping it: from which `YYYY-MM` month, and how
     * much that took off the forecast a month (less than `monthly` when its
     * category had less left).
     */
    drop: { id: string; month: string; reduction: number } | null;
    kept: boolean;
}

export interface ForecastRecurringCharges {
    /** The most expensive a month first. */
    charges: ForecastRecurringCharge[];
    /** What they all cost a month, per currency. */
    totals: { currencyCode: string; monthly: number }[];
}
