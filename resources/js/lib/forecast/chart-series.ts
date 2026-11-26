import { type ProjectedMonth } from '@/lib/forecast/types';
import { type ForecastData } from '@/types/forecast';

export type ChartMonthStatus = 'past' | 'current' | 'future' | 'locked';

export interface ForecastChartPoint {
    month: string;
    status: ChartMonthStatus;
    income: number | null;
    expenses: number | null;
    close: number | null;
    /** The likely range of the close, low then high. */
    band: [number, number] | null;
    min: number | null;
}

function projectedPoint(
    month: ProjectedMonth,
    status: 'current' | 'future',
    soFar = { income: 0, expenses: 0, min: null as number | null },
): ForecastChartPoint {
    return {
        month: month.month,
        status,
        income: soFar.income + month.income,
        expenses: soFar.expenses + month.expenses,
        close: month.close,
        band: [month.close - month.band, month.close + month.band],
        min: soFar.min === null ? month.min : Math.min(soFar.min, month.min),
    };
}

/**
 * The chart's months: the real months before today's, today's (what really
 * happened so far plus what is still to come), the projected months and,
 * without Pro, the locked ones, which carry no figure at all.
 */
export function buildChartSeries(forecast: ForecastData): ForecastChartPoint[] {
    const [current, ...future] = forecast.projection;
    const today = forecast.past[forecast.past.length - 1];

    return [
        ...forecast.past.slice(0, -1).map(
            (month): ForecastChartPoint => ({
                ...month,
                status: 'past',
                band: null,
            }),
        ),
        ...(current ? [projectedPoint(current, 'current', today)] : []),
        ...future.map((month) => projectedPoint(month, 'future')),
        ...forecast.months
            .filter((column) => column.status === 'locked')
            .map(
                (column): ForecastChartPoint => ({
                    month: column.month,
                    status: 'locked',
                    income: null,
                    expenses: null,
                    close: null,
                    band: null,
                    min: null,
                }),
            ),
    ];
}
