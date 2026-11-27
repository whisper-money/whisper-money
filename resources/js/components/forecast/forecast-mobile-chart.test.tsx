import { type ForecastChartPoint } from '@/lib/forecast/chart-series';
import { type ForecastData } from '@/types/forecast';
import { fireEvent, render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ForecastMobileChart } from './forecast-mobile-chart';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-format-amount', () => ({
    useFormatAmount: () => ({
        formatAmount: (amount: number, currency: string) =>
            `${currency} ${amount / 100}`,
    }),
}));

function point(
    month: string,
    status: ForecastChartPoint['status'],
): ForecastChartPoint {
    const locked = status === 'locked';

    return {
        month,
        status,
        income: locked ? null : 320000,
        expenses: locked ? null : 290000,
        close: locked ? null : 1020000,
        band: status === 'past' || locked ? null : [980000, 1060000],
        min: locked ? null : 900000,
    };
}

vi.mock('@/lib/forecast/chart-series', () => ({
    buildChartSeries: () => [
        point('2026-10', 'past'),
        point('2026-11', 'current'),
        point('2026-12', 'future'),
        point('2027-01', 'locked'),
    ],
}));

const forecast = {
    scope: 'account',
    currencyCode: 'EUR',
    account: { alertThreshold: 20000 },
} as unknown as ForecastData;

const onSelectMonth = vi.fn();

function renderChart() {
    render(
        <ForecastMobileChart
            forecast={forecast}
            threshold={20000}
            selectedMonth="2026-11"
            onSelectMonth={onSelectMonth}
        />,
    );
}

const bar = (month: string) =>
    document.querySelector(
        `[data-test="forecast-mobile-bar-${month}"]`,
    ) as HTMLElement;
const tooltip = () =>
    document.querySelector<HTMLElement>('[data-test="forecast-chart-tooltip"]');

describe('ForecastMobileChart', () => {
    beforeEach(() => onSelectMonth.mockClear());

    it('picks a tapped month and shows its tooltip over the bar', () => {
        renderChart();

        fireEvent.click(bar('2026-12'));

        expect(onSelectMonth).toHaveBeenCalledWith('2026-12');
        expect(tooltip()).toHaveTextContent('Forecast');
        expect(bar('2026-12')).toHaveAttribute('aria-expanded', 'true');
        expect(bar('2026-12')).toHaveAttribute(
            'aria-describedby',
            tooltip()?.id,
        );
    });

    it('hides the tooltip on a second tap of the same month', () => {
        renderChart();

        fireEvent.click(bar('2026-11'));
        expect(tooltip()).toHaveTextContent('So far + forecast');

        fireEvent.click(bar('2026-11'));
        expect(tooltip()).toBeNull();
        expect(onSelectMonth).toHaveBeenCalledTimes(2);
    });

    it('hides the tooltip on a tap outside the bars, or a scroll', () => {
        renderChart();

        fireEvent.click(bar('2026-12'));
        fireEvent.pointerDown(document.body);
        expect(tooltip()).toBeNull();

        fireEvent.click(bar('2026-12'));
        fireEvent.scroll(window);
        expect(tooltip()).toBeNull();
    });

    it("shows the last closed month's tooltip without picking it", () => {
        renderChart();

        fireEvent.click(bar('2026-10'));

        expect(onSelectMonth).not.toHaveBeenCalled();
        expect(tooltip()).toHaveTextContent('Actual');
    });

    it('picks a locked month with no tooltip, as on desktop', () => {
        renderChart();

        fireEvent.click(bar('2026-12'));
        fireEvent.click(bar('2027-01'));

        expect(onSelectMonth).toHaveBeenLastCalledWith('2027-01');
        expect(tooltip()).toBeNull();
    });
});
