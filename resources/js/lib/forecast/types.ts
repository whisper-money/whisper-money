/**
 * Input and output of the client-side forecast engine. Same shapes, in the
 * same camelCase, as the PHP engine in app/Services/Forecast, so the server's
 * projection input can be fed straight in. Dates are `YYYY-MM-DD` strings,
 * months `YYYY-MM`, money signed integer minor units.
 */

export type ForecastFrequency =
    | 'monthly'
    | 'bimonthly'
    | 'quarterly'
    | 'yearly'
    | 'once';

export interface ForecastItemVersion {
    effectiveFrom: string;
    amount: number;
}

/** A "this month only" change to the occurrence scheduled on `occurrenceDate`. */
export interface ForecastItemOverride {
    occurrenceDate: string;
    amount?: number | null;
    movedTo?: string | null;
    skipped?: boolean;
}

export interface ExpandableForecastItem {
    id: string;
    accountId: string;
    /** Set for a transfer to another account; null for spend, income or a transfer out of the app. */
    destinationAccountId?: string | null;
    frequency: ForecastFrequency;
    anchorDate: string;
    endsOn?: string | null;
    versions: ForecastItemVersion[];
    overrides?: ForecastItemOverride[];
}

/**
 * One dated movement of a forecast line. `occurrenceDate` is the date the
 * schedule gives it; `date` is when it lands, after any move.
 */
export interface ForecastOccurrence {
    itemId: string;
    accountId: string;
    destinationAccountId: string | null;
    date: string;
    occurrenceDate: string;
    amount: number;
}

export interface ProjectionAccount {
    id: string;
    /** Balance at the start of the projection's first day. */
    openingBalance: number;
    annualRateBps?: number | null;
    minimumBalance?: number | null;
}

/** Spend expected beyond the concrete lines, in positive minor units, spread evenly over the month. */
export interface MonthlyEstimate {
    accountId: string;
    month: string;
    amount: number;
}

export interface ProjectionInput {
    accounts: ProjectionAccount[];
    occurrences: ForecastOccurrence[];
    estimates?: MonthlyEstimate[];
    startDate: string;
    horizonMonths: number;
}

export interface ProjectedMonth {
    month: string;
    /** Lowest end-of-day balance of the month. */
    min: number;
    minDate: string | null;
    close: number;
    /** Income, transfers left out. */
    income: number;
    /** Concrete spend plus the estimated spend, transfers left out. */
    expenses: number;
    /** Net money moved in (positive) or out (negative) by transfers. */
    transfers: number;
    growth: number;
    estimated: number;
    /** 40% of the month's estimate: the uncertainty of the estimate alone. */
    band: number;
    pessimisticMin: number;
}

export interface AccountProjection {
    accountId: string;
    minimumBalance: number | null;
    months: ProjectedMonth[];
}

export interface Projection {
    accounts: AccountProjection[];
    total: ProjectedMonth[];
}
