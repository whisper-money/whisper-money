import { type ProjectedMonth } from '@/lib/forecast/types';
import {
    type ForecastAllData,
    type ForecastData,
    type ForecastGroup,
    type ForecastLine,
} from '@/types/forecast';

/**
 * The forecast page's sample data, shared by the desktop and the mobile
 * tests: one checking account that runs short in April 2027, and the
 * "All accounts" view of it next to a savings account.
 */

export const MONTHS = [
    '2026-11',
    '2026-12',
    '2027-01',
    '2027-02',
    '2027-03',
    '2027-04',
];

export function projected(month: string, min: number): ProjectedMonth {
    return {
        month,
        min,
        minDate: `${month}-10`,
        close: min + 100000,
        income: 300000,
        expenses: 200000,
        transfers: 0,
        growth: 0,
        estimated: 20000,
        estimatedIncome: 0,
        band: 8000,
        pessimisticMin: min - 8000,
    };
}

export function line(overrides: Partial<ForecastLine>): ForecastLine {
    return {
        id: 'rent',
        name: 'Rent',
        kind: 'monthly',
        source: 'detected',
        anchorDate: '2026-01-05',
        direction: null,
        counterpartyName: null,
        amounts: {},
        current: {
            planned: -190000,
            real: -190000,
            date: '2026-11-05',
            status: 'matched',
        },
        accountId: 'checking',
        categoryId: 'home',
        definition: {
            id: overrides.id ?? 'rent',
            accountId: 'checking',
            destinationAccountId: null,
            frequency: 'monthly',
            anchorDate: '2026-01-05',
            endsOn: null,
            versions: [{ effectiveFrom: '2026-01-05', amount: -190000 }],
            overrides: [],
        },
        occurrenceDates: {},
        matchText: null,
        amountTolerance: 0,
        dateWindowDays: 3,
        payments: [],
        drift: null,
        ...overrides,
    };
}

export const home: ForecastGroup = {
    key: 'home',
    section: 'expenses',
    categoryId: 'home',
    name: 'Home',
    icon: null,
    color: null,
    items: [
        line({
            amounts: Object.fromEntries(MONTHS.map((m) => [m, -190000])),
        }),
        line({
            id: 'insurance',
            name: 'Car insurance',
            kind: 'yearly',
            source: 'manual',
            anchorDate: '2026-04-10',
            amounts: { '2027-04': -700000 },
            current: { planned: null, real: null, date: null, status: 'none' },
        }),
    ],
    estimate: {
        kind: 'expense',
        basis: 'median',
        budgetAmount: null,
        amounts: Object.fromEntries(MONTHS.slice(1).map((m) => [m, -6000])),
        current: { spent: 2000, planned: 6000, left: 4000 },
    },
};

export function forecast(overrides: Partial<ForecastData> = {}): ForecastData {
    return {
        scope: 'account',
        today: '2026-11-26',
        horizon: 6,
        isPro: true,
        currencyCode: 'EUR',
        account: {
            id: 'checking',
            name: 'Checking',
            type: 'checking',
            currencyCode: 'EUR',
            projectionOnly: false,
            minimumBalance: null,
            annualRateBps: null,
            trend: null,
            alertThreshold: 0,
        },
        accountChoices: [
            {
                id: 'checking',
                name: 'Checking',
                type: 'checking',
                included: true,
                projectionOnly: false,
            },
            {
                id: 'savings',
                name: 'Savings',
                type: 'savings',
                included: true,
                projectionOnly: false,
            },
        ],
        accounts: [
            {
                id: 'checking',
                name: 'Checking',
                type: 'checking',
                currencyCode: 'EUR',
                projectionOnly: false,
                trend: null,
                hasAlert: true,
            },
            {
                id: 'savings',
                name: 'Savings',
                type: 'savings',
                currencyCode: 'EUR',
                projectionOnly: false,
                trend: null,
                hasAlert: false,
            },
        ],
        months: [
            ...MONTHS.map((month, index) => ({
                month,
                status:
                    index === 0 ? ('current' as const) : ('future' as const),
            })),
            { month: '2027-05', status: 'future' as const },
        ],
        projection: [...MONTHS, '2027-05'].map((month) =>
            projected(month, month === '2027-04' ? -50000 : 110000),
        ),
        past: [
            {
                month: '2026-09',
                income: 300000,
                expenses: 190000,
                close: 90000,
                min: 80000,
            },
            {
                month: '2026-10',
                income: 300000,
                expenses: 190000,
                close: 100000,
                min: 90000,
            },
            {
                month: '2026-11',
                income: 300000,
                expenses: 192000,
                close: 100000,
                min: 95000,
            },
        ],
        alert: {
            level: 'strong',
            month: '2027-04',
            min: -50000,
            minDate: '2027-04-10',
            softMonths: [],
            causes: [{ name: 'Car insurance', amount: 700000 }],
            transfer: null,
        },
        lockedAlertCount: null,
        kpis: {
            balanceToday: 100000,
            end: { month: '2027-05', close: 210000, band: 8000 },
            lowest: { month: '2027-04', min: -50000, minDate: '2027-04-10' },
            concreteShare: 90,
        },
        plan: { income: [], expenses: [home], transfers: [] },
        ...overrides,
    };
}

const savingsMonths = MONTHS.map((month) => projected(month, 800000));
const checkingMonths = MONTHS.map((month) =>
    projected(month, month === '2027-04' ? -50000 : 110000),
);
export const allTotal = MONTHS.map((month, index) => ({
    ...projected(month, 0),
    close: checkingMonths[index].close + savingsMonths[index].close,
    growth: index === 0 ? 0 : 9000,
}));

export function allForecast(
    overrides: Partial<ForecastAllData> = {},
): ForecastAllData {
    const base = forecast();

    return {
        scope: 'all',
        today: base.today,
        horizon: base.horizon,
        isPro: true,
        accountChoices: base.accountChoices,
        currencyCode: 'EUR',
        account: null,
        accounts: [
            { ...base.accounts[0], hasAlert: true },
            {
                id: 'savings',
                name: 'Savings',
                type: 'savings',
                currencyCode: 'EUR',
                projectionOnly: false,
                trend: null,
                hasAlert: false,
            },
        ],
        months: base.months,
        projection: allTotal,
        unconvertedCurrencies: [],
        past: base.past,
        accountForecasts: [
            {
                ...base.account,
                minimumBalance: 30000,
                projection: checkingMonths,
            },
            {
                id: 'savings',
                name: 'Savings',
                type: 'savings',
                currencyCode: 'EUR',
                projectionOnly: false,
                minimumBalance: null,
                annualRateBps: 1200,
                trend: null,
                alertThreshold: 0,
                projection: savingsMonths,
            },
        ],
        alert: {
            level: 'split',
            accountId: 'checking',
            month: '2027-04',
            min: -50000,
            minDate: '2027-04-10',
            softMonths: [],
            transfer: {
                fromAccountId: 'savings',
                amount: 50000,
                date: '2027-04-01',
                month: '2027-04',
            },
        },
        lockedAlertCount: null,
        kpis: {
            balanceToday: 1000000,
            accountCount: 2,
            end: { month: '2027-04', close: 2000000, band: 8000 },
            saved: 500000,
            growth: 45000,
            moved: 250000,
        },
        plan: {
            income: [],
            expenses: [
                {
                    ...home,
                    items: [
                        home.items[0],
                        line({
                            id: 'cleaning',
                            name: 'Cleaning',
                            accountId: 'savings',
                            amounts: { '2026-12': -10000 },
                        }),
                    ],
                    estimates: [{ ...home.estimate!, accountId: 'savings' }],
                    totals: { '2026-12': -206000 },
                    current: { real: -192000, pending: false },
                },
            ],
            transfers: [
                line({
                    id: 'to-savings',
                    name: 'To savings',
                    kind: 'transfer',
                    direction: 'in',
                    counterpartyName: 'Checking',
                    amounts: { '2026-12': 50000 },
                    definition: {
                        ...home.items[0].definition,
                        id: 'to-savings',
                        destinationAccountId: 'savings',
                    },
                    occurrenceDates: { '2026-12': '2026-12-02' },
                }),
            ],
            growth: Object.fromEntries(
                allTotal.map((month) => [month.month, month.growth]),
            ),
            transferEffect: Object.fromEntries(
                MONTHS.map((month) => [month, 0]),
            ),
        },
        ...overrides,
    };
}
