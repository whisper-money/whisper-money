import { shortAccountCount, shortAccounts } from '@/lib/forecast/all-accounts';
import { type ForecastChartPoint } from '@/lib/forecast/chart-series';
import {
    chartTooltipData,
    mobileTooltipLeft,
} from '@/lib/forecast/chart-tooltip';
import { type ProjectedMonth } from '@/lib/forecast/types';
import {
    type ForecastAccountForecast,
    type ForecastAllData,
    type ForecastData,
} from '@/types/forecast';
import { describe, expect, it } from 'vitest';

function projected(month: string, min: number): ProjectedMonth {
    return {
        month,
        min,
        minDate: null,
        close: min + 50000,
        income: 300000,
        expenses: 250000,
        transfers: 0,
        growth: 0,
        estimated: 0,
        estimatedIncome: 0,
        band: 10000,
        pessimisticMin: min,
    };
}

function account(
    id: string,
    threshold: number | null,
    mins: Record<string, number>,
): ForecastAccountForecast {
    return {
        id,
        name: `Account ${id}`,
        type: 'checking',
        currencyCode: 'EUR',
        minimumBalance: null,
        annualRateBps: null,
        projectionOnly: false,
        trend: null,
        alertThreshold: threshold,
        projection: Object.entries(mins).map(([month, min]) =>
            projected(month, min),
        ),
    };
}

const checking = account('checking', 20000, {
    '2026-11': 90000,
    '2026-12': -14000,
});
const savings = account('savings', 100000, {
    '2026-11': 500000,
    '2026-12': 65000,
});
const brokerage = account('brokerage', null, {
    '2026-11': -999999,
    '2026-12': -999999,
});

const all = {
    scope: 'all',
    currencyCode: 'EUR',
    account: null,
    accountForecasts: [checking, savings, brokerage],
} as unknown as ForecastAllData;

const single = {
    scope: 'account',
    currencyCode: 'EUR',
    account: { alertThreshold: 20000 },
} as unknown as ForecastData;

function point(
    status: ForecastChartPoint['status'],
    month = '2026-12',
): ForecastChartPoint {
    const locked = status === 'locked';

    return {
        month,
        status,
        income: locked ? null : 320000,
        expenses: locked ? null : 390000,
        close: locked ? null : 1020000,
        band: status === 'past' || locked ? null : [980000, 1060000],
        min: locked ? null : 900000,
    };
}

describe('shortAccounts', () => {
    it('lists the accounts under their threshold in a month, with how low they go', () => {
        expect(shortAccounts(all, '2026-12')).toEqual([
            { account: checking, min: -14000 },
            { account: savings, min: 65000 },
        ]);
        expect(shortAccountCount(all, '2026-12')).toBe(2);
    });

    it('never counts an account that does not warn, nor a month it does not reach', () => {
        expect(shortAccounts(all, '2026-11')).toEqual([]);
        expect(shortAccounts(all, '2027-01')).toEqual([]);
    });
});

describe('chartTooltipData', () => {
    it('says nothing about a month locked behind Pro', () => {
        expect(chartTooltipData(point('locked'), single)).toBeNull();
    });

    it('reads a real month as actual, with no range', () => {
        expect(chartTooltipData(point('past', '2026-09'), single)).toEqual({
            month: '2026-09',
            kind: 'actual',
            income: 320000,
            expenses: 390000,
            close: 1020000,
            range: null,
            min: 900000,
            shortAccounts: [],
        });
    });

    it('reads the month in progress as so far plus forecast, with its range', () => {
        const data = chartTooltipData(point('current'), single);

        expect(data?.kind).toBe('today');
        expect(data?.range).toEqual([980000, 1060000]);
    });

    it('lists the accounts that run short in the "All accounts" view, and drops the lowest balance', () => {
        const data = chartTooltipData(point('future'), all);

        expect(data?.kind).toBe('forecast');
        expect(data?.min).toBeNull();
        expect(data?.shortAccounts.map(({ account }) => account.id)).toEqual([
            'checking',
            'savings',
        ]);
    });

    it('lists no account in the single-account view', () => {
        expect(
            chartTooltipData(point('future'), single)?.shortAccounts,
        ).toEqual([]);
    });
});

describe('mobileTooltipLeft', () => {
    const phone = { chartLeft: 32, tooltipWidth: 200, screenWidth: 390 };

    it("centres the tooltip over the tapped bar, from the chart's left", () => {
        expect(mobileTooltipLeft({ ...phone, barCenter: 195 })).toBe(63);
    });

    it('keeps it inside the screen next to the first and the last bar', () => {
        expect(mobileTooltipLeft({ ...phone, barCenter: 40 })).toBe(8 - 32);
        expect(mobileTooltipLeft({ ...phone, barCenter: 350 })).toBe(
            390 - 8 - 200 - 32,
        );
    });

    it("starts at the screen's margin when the tooltip is as wide as the screen", () => {
        expect(
            mobileTooltipLeft({ ...phone, tooltipWidth: 400, barCenter: 200 }),
        ).toBe(8 - 32);
    });
});
