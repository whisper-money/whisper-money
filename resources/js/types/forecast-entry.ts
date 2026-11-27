/**
 * The forecast's ways in from other pages, behind the Forecast flag. Money is
 * in signed minor units, months are `YYYY-MM`, dates `YYYY-MM-DD`.
 */

/**
 * The dashboard's warning that an account runs short, as
 * App\Services\Forecast\ForecastDashboardAlert sends it.
 */
export interface ForecastDashboardAlertData {
    id: string;
    accountId: string;
    accountName: string;
    currencyCode: string;
    month: string;
    /** The month's lowest balance; null on an alert stored before it was kept. */
    lowestBalance: number | null;
    lowestOn: string | null;
    /** The largest charges behind the dip, positive. */
    causes: { name: string; amount: number }[];
    /** The day "remind me" puts the card away until; null when already due. */
    remindOn: string | null;
}

/**
 * What the forecast still expects for the rest of the month in progress, as
 * App\Services\Forecast\ForecastRestOfMonth sends it.
 */
export interface ForecastRestOfMonthData {
    month: string;
    currencyCode: string;
    /** The lines not paid yet, soonest first. */
    lines: { name: string; date: string; amount: number }[];
    /** What is left of each parent category's estimate, signed (income positive); a null name is "Other income" or "Other spending". */
    estimates: {
        name: string | null;
        kind: 'income' | 'expense';
        amount: number;
    }[];
    endBalance: number;
}
