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

export type ForecastLineSource = 'detected' | 'manual' | 'suggested';

export interface ForecastAccountTab {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
    hasAlert: boolean;
}

export interface ForecastAccountSummary {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
    minimumBalance: number | null;
    /** Yearly growth of an investment or property, in basis points. */
    annualRateBps: number | null;
    /** The balance the warnings read against; null for a card or a loan without a minimum. */
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
    /** "It is now 950. Update from December?" */
    drift: { amount: number; from: string } | null;
}

export interface ForecastEstimate {
    basis: 'median' | 'budget' | 'adjustment' | null;
    budgetAmount: number | null;
    /** Negative amounts by month, the month in progress left out. */
    amounts: Record<string, number>;
    current: { spent: number; planned: number; left: number } | null;
}

export interface ForecastGroup {
    key: string;
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
}

export interface ForecastEmpty extends ForecastBase {
    account: null;
    accounts: [];
}

export interface ForecastData extends ForecastBase {
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

export type ForecastSuggestionFrequency =
    | 'monthly'
    | 'bimonthly'
    | 'quarterly'
    | 'yearly'
    | 'once';

/**
 * A line the history suggests, as App\Services\Forecast\ForecastReviewPresenter
 * sends it. `amount` is signed from the account, in its minor units; `key`
 * is what the review sends back to keep or dismiss it.
 */
export interface ForecastSuggestion {
    key: string;
    kind: 'recurring' | 'transfer' | 'annual';
    /** Recognised by its description, so it can be merged with another. */
    mergeable: boolean;
    name: string;
    accountId: string;
    accountName: string | null;
    destinationAccountId: string | null;
    destinationAccountName: string | null;
    /** A transfer whose other side is not in the app. */
    destinationUnknown: boolean;
    currencyCode: string;
    frequency: ForecastSuggestionFrequency;
    anchorDay: number;
    nextDate: string;
    amount: number;
    amountTolerance: number;
    confidence: 'high' | 'review';
    chargeCount: number;
    expectedCount: number;
}

export interface ForecastAnnualSuggestion extends ForecastSuggestion {
    /** The charges it stands on, oldest first. */
    evidence: { date: string; amount: number }[];
}

export interface ForecastReviewAccount {
    id: string;
    name: string;
    currencyCode: string;
}

/** How the day-to-day spend is estimated, in the user's currency. */
export interface ForecastDailySpend {
    currencyCode: string;
    budgets: { name: string; amount: number }[];
    median: { name: string; amount: number } | null;
    unusableBudgets: string[];
}

export interface ForecastReview {
    recurring: ForecastSuggestion[];
    annual: ForecastAnnualSuggestion[];
    accounts: ForecastReviewAccount[];
    dailySpend: ForecastDailySpend | null;
}

export interface ForecastReviewPrompt {
    /** Whether the space has any forecast line: without one the review opens on its own. */
    hasLines: boolean;
}

export interface ForecastPageProps {
    forecast: ForecastData | ForecastEmpty;
    projectionInput: ProjectionInput | null;
    reviewPrompt: ForecastReviewPrompt;
    /** Suggestions not answered yet; deferred, so undefined at first. */
    newSuggestionCount?: number;
    /** Only sent when the page asks for it (`only: ['review']`). */
    review?: ForecastReview;
}
