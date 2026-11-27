/**
 * Input and output of the client-side forecast engine. Same shapes, in the
 * same camelCase, as the PHP engine in app/Services/Forecast, so the server's
 * projection input can be fed straight in. Dates are `YYYY-MM-DD` strings,
 * months `YYYY-MM`, money signed integer minor units.
 */

export type ForecastFrequency =
    | 'weekly'
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
    /** The balance trend added on each whole month's close, in place of the yearly rate. */
    trendSlope?: number | null;
    /** The trend's uncertainty, added to each whole month's band. */
    trendBand?: number | null;
}

export type MonthlyEstimateKind = 'income' | 'expense';

/**
 * Money a parent category is expected to move beyond the concrete lines, in
 * positive minor units: spend for an expense estimate (the default), money
 * in for an income one. Income lands whole on `day`; spend follows
 * `profile` (the weight of each day of the month, 31 entries); with neither
 * it is spread evenly over the month. See MonthlyEstimate.php.
 */
export interface MonthlyEstimate {
    accountId: string;
    month: string;
    amount: number;
    kind?: MonthlyEstimateKind;
    categoryId?: string | null;
    day?: number | null;
    profile?: number[] | null;
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
    /** Concrete income plus the estimated income, transfers left out. */
    income: number;
    /** Concrete spend plus the estimated spend, transfers left out. */
    expenses: number;
    /** Net money moved in (positive) or out (negative) by transfers. */
    transfers: number;
    growth: number;
    /** The estimated spend. */
    estimated: number;
    /** The estimated income. */
    estimatedIncome: number;
    /** 40% of the estimated spend plus 10% of the estimated income, plus a balance trend's band on whole months. */
    band: number;
    /** The minimum less the estimates' band alone: a trend's band never raises a warning. */
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
