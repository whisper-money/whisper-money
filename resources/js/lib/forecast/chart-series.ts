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

/**
 * The chart's months: the real months before today's, today's (what really
 * happened so far plus what is still to come), the projected months and,
 * without Pro, the locked ones, which carry no figure at all.
 */
export function buildChartSeries(forecast: ForecastData): ForecastChartPoint[] {
    const [current, ...future] = forecast.projection;
    const pastMonths = forecast.past.slice(0, -1);
    const today = forecast.past[forecast.past.length - 1];

    const points: ForecastChartPoint[] = pastMonths.map((month) => ({
        month: month.month,
        status: 'past',
        income: month.income,
        expenses: month.expenses,
        close: month.close,
        band: null,
        min: month.min,
    }));

    if (current) {
        points.push({
            month: current.month,
            status: 'current',
            income: (today?.income ?? 0) + current.income,
            expenses: (today?.expenses ?? 0) + current.expenses,
            close: current.close,
            band: [current.close - current.band, current.close + current.band],
            min: Math.min(today?.min ?? current.min, current.min),
        });
    }

    points.push(
        ...future.map(
            (month): ForecastChartPoint => ({
                month: month.month,
                status: 'future',
                income: month.income,
                expenses: month.expenses,
                close: month.close,
                band: [month.close - month.band, month.close + month.band],
                min: month.min,
            }),
        ),
    );

    points.push(
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
    );

    return points;
}
