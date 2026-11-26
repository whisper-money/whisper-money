import {
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
    hasAlert: boolean;
}

export interface ForecastAccountSummary {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
    minimumBalance: number | null;
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

export interface ForecastPageProps {
    forecast: ForecastData | ForecastEmpty;
    projectionInput: ProjectionInput | null;
}
