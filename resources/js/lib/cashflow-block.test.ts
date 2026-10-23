import { type TransactionFilters } from '@/types/transaction';
import { formatLocalDate, toLocalDate } from '@/utils/date';
import { describe, expect, it } from 'vitest';
import {
    cashflowBlockFromParam,
    cashflowBlockParam,
    cashflowBlockTransactionsUrl,
    matchingQuickPeriod,
    quickPeriodRange,
    withoutDatelessCashflowBlock,
} from './cashflow-block';

const NO_FILTERS: TransactionFilters = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: [],
    accountIds: [],
    labelIds: [],
    creditorName: '',
    debtorName: '',
    searchText: '',
    aiCategorizedOnly: false,
};

function range(period: Parameters<typeof quickPeriodRange>[0], day: string) {
    const { from, to } = quickPeriodRange(period, toLocalDate(day));

    return [formatLocalDate(from), formatLocalDate(to)];
}

describe('cashflowBlockTransactionsUrl', () => {
    it('links the transactions list filtered to a block over a range', () => {
        const url = new URL(
            cashflowBlockTransactionsUrl('fixed', '2026-10-01', '2026-10-31'),
            'https://example.test',
        );

        expect(url.pathname).toBe('/transactions');
        expect(Object.fromEntries(url.searchParams)).toEqual({
            cashflow_block: 'fixed',
            date_from: '2026-10-01',
            date_to: '2026-10-31',
        });
    });

    it('takes local dates and keeps their calendar day', () => {
        const url = new URL(
            cashflowBlockTransactionsUrl(
                'invested',
                toLocalDate('2026-01-01'),
                toLocalDate('2026-12-31'),
            ),
            'https://example.test',
        );

        expect(url.searchParams.get('date_from')).toBe('2026-01-01');
        expect(url.searchParams.get('date_to')).toBe('2026-12-31');
    });
});

describe('the cashflow_block URL param round trip', () => {
    it.each(['fixed', 'variable', 'general', 'saved', 'invested'] as const)(
        'reads back %s as it was sent',
        (block) => {
            const filters = {
                ...NO_FILTERS,
                dateFrom: toLocalDate('2026-10-01'),
                dateTo: toLocalDate('2026-10-31'),
                cashflowBlock: block,
            };

            expect(cashflowBlockFromParam(cashflowBlockParam(filters))).toBe(
                block,
            );
        },
    );

    it('ignores an unknown or missing value', () => {
        expect(cashflowBlockFromParam('income')).toBeNull();
        expect(cashflowBlockFromParam(null)).toBeNull();
        expect(cashflowBlockFromParam(undefined)).toBeNull();
    });

    it('drops the block once either date is cleared', () => {
        const filters = {
            ...NO_FILTERS,
            dateFrom: toLocalDate('2026-10-01'),
            dateTo: toLocalDate('2026-10-31'),
            cashflowBlock: 'general' as const,
        };

        expect(cashflowBlockParam({ ...filters, dateTo: null })).toBeNull();
        expect(cashflowBlockParam({ ...filters, dateFrom: null })).toBeNull();
        expect(cashflowBlockParam({ ...filters, cashflowBlock: null })).toBe(
            null,
        );
    });
});

describe('quick periods', () => {
    it('covers the month, quarter and year containing the date', () => {
        expect(range('month', '2026-02-14')).toEqual([
            '2026-02-01',
            '2026-02-28',
        ]);
        expect(range('quarter', '2026-05-20')).toEqual([
            '2026-04-01',
            '2026-06-30',
        ]);
        expect(range('year', '2026-05-20')).toEqual([
            '2026-01-01',
            '2026-12-31',
        ]);
    });

    it('recognises a range that is exactly one of them', () => {
        const day = (value: string) => toLocalDate(value);

        expect(matchingQuickPeriod(day('2026-10-01'), day('2026-10-31'))).toBe(
            'month',
        );
        expect(matchingQuickPeriod(day('2026-10-01'), day('2026-12-31'))).toBe(
            'quarter',
        );
        expect(matchingQuickPeriod(day('2026-01-01'), day('2026-12-31'))).toBe(
            'year',
        );
        expect(
            matchingQuickPeriod(day('2026-10-02'), day('2026-10-31')),
        ).toBeNull();
        expect(matchingQuickPeriod(null, day('2026-10-31'))).toBeNull();
    });
});

describe('withoutDatelessCashflowBlock', () => {
    const october = {
        ...NO_FILTERS,
        dateFrom: toLocalDate('2026-10-01'),
        dateTo: toLocalDate('2026-10-31'),
        cashflowBlock: 'fixed' as const,
    };

    it('keeps the block while both dates are set', () => {
        expect(withoutDatelessCashflowBlock(october)).toBe(october);
    });

    it('drops the block, and its chip, once a date is cleared', () => {
        expect(
            withoutDatelessCashflowBlock({ ...october, dateFrom: null }),
        ).toEqual({ ...october, dateFrom: null, cashflowBlock: null });
    });
});
