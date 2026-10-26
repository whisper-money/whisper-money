import { type Transaction } from '@/types/transaction';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    formatDayBand,
    formatDayHeading,
    formatTransactionCount,
    isSortedByDate,
    reconcileDaySummary,
    sumDayTotal,
} from './transaction-day-groups';

function transaction(amount: number, currency_code = 'EUR'): Transaction {
    return { amount, currency_code } as Transaction;
}

describe('isSortedByDate', () => {
    it('groups while the table is sorted by date, either way', () => {
        expect(isSortedByDate([{ id: 'transaction_date', desc: true }])).toBe(
            true,
        );
        expect(isSortedByDate([{ id: 'transaction_date', desc: false }])).toBe(
            true,
        );
    });

    it('does not group under any other sort', () => {
        expect(isSortedByDate([{ id: 'amount', desc: true }])).toBe(false);
        expect(isSortedByDate([])).toBe(false);
    });
});

describe('formatDayBand', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-26T12:00:00'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('puts the day and a short date on one line', () => {
        expect(formatDayBand('2026-10-26', 'es-ES')).toBe('Today · 26 oct');
        expect(formatDayBand('2026-10-24', 'es-ES')).toBe('sábado · 24 oct');
        expect(formatDayBand('2025-12-31', 'en-US')).toBe(
            'Wednesday · Dec 31, 2025',
        );
    });
});

describe('formatDayHeading', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-26T12:00:00'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('names today and yesterday, with the full date beside them', () => {
        expect(formatDayHeading('2026-10-26', 'en-US')).toEqual({
            name: 'Today',
            date: 'Monday, October 26',
        });
        expect(formatDayHeading('2026-10-25', 'es-ES')).toEqual({
            name: 'Yesterday',
            date: 'domingo, 25 de octubre',
        });
    });

    it('names any other day by its weekday', () => {
        expect(formatDayHeading('2026-10-21', 'es-ES')).toEqual({
            name: 'Miércoles',
            date: '21 de octubre',
        });
    });

    it('adds the year outside the current one', () => {
        expect(formatDayHeading('2025-12-31', 'en-US')).toEqual({
            name: 'Wednesday',
            date: 'December 31, 2025',
        });
    });
});

describe('formatTransactionCount', () => {
    it('says one transaction in the singular', () => {
        expect(formatTransactionCount(1)).toBe('1 transaction');
        expect(formatTransactionCount(3)).toBe('3 transactions');
    });
});

describe('sumDayTotal', () => {
    const asIs = (t: Transaction) => t.amount;

    it('adds the day up, income and expenses alike', () => {
        expect(
            sumDayTotal(
                [transaction(-1250), transaction(-750), transaction(5000)],
                asIs,
            ),
        ).toEqual({ status: 'ready', amount: 3000 });
    });

    it('adds converted amounts for rows in another currency', () => {
        const converted = (t: Transaction) =>
            t.currency_code === 'USD' ? t.amount / 2 : t.amount;

        expect(
            sumDayTotal(
                [transaction(-1000), transaction(-4000, 'USD')],
                converted,
            ),
        ).toEqual({ status: 'ready', amount: -3000 });
    });

    it('waits while a conversion is still on its way', () => {
        expect(
            sumDayTotal([transaction(-1000), transaction(-4000, 'USD')], (t) =>
                t.currency_code === 'USD' ? undefined : t.amount,
            ),
        ).toEqual({ status: 'pending' });
    });

    it('leaves the total out when one amount has no rate', () => {
        expect(
            sumDayTotal(
                [transaction(-1000), transaction(-4000, 'USD'), transaction(1)],
                (t) => (t.currency_code === 'USD' ? null : t.amount),
            ),
        ).toEqual({ status: 'missing' });
    });
});

describe('reconcileDaySummary', () => {
    function row(
        id: string,
        amount: number,
        currency_code = 'EUR',
    ): Transaction {
        return { id, amount, currency_code } as Transaction;
    }

    const served = [row('a', -1000), row('b', -500, 'USD')];

    it('keeps the server figure while the page changed nothing', () => {
        expect(
            reconcileDaySummary(
                { count: 9, total: -12000 },
                served,
                [...served],
                'EUR',
            ),
        ).toEqual({ count: 9, total: { status: 'ready', amount: -12000 } });
    });

    it('moves the figure by a deleted or edited row in the user currency', () => {
        expect(
            reconcileDaySummary(
                { count: 9, total: -12000 },
                served,
                [row('b', -500, 'USD')],
                'EUR',
            ),
        ).toEqual({ count: 8, total: { status: 'ready', amount: -11000 } });

        expect(
            reconcileDaySummary(
                { count: 9, total: -12000 },
                served,
                [row('a', -2500), row('b', -500, 'USD')],
                'EUR',
            ),
        ).toEqual({ count: 9, total: { status: 'ready', amount: -13500 } });
    });

    it('counts a day the server never saw from the rows moved into it', () => {
        expect(
            reconcileDaySummary(undefined, [], [row('c', 700)], 'EUR'),
        ).toEqual({ count: 1, total: { status: 'ready', amount: 700 } });
    });

    it('waits for the figure of a day served without one', () => {
        expect(reconcileDaySummary(undefined, served, served, 'EUR')).toEqual({
            count: 2,
            total: { status: 'pending' },
        });
    });

    it('leaves the total out when a changed row needs a rate', () => {
        expect(
            reconcileDaySummary(
                { count: 9, total: -12000 },
                served,
                [row('a', -1000)],
                'EUR',
            ).total,
        ).toEqual({ status: 'missing' });
    });

    it('leaves the total out when the server had no rate for the day', () => {
        expect(
            reconcileDaySummary(
                { count: 2, total: null },
                served,
                served,
                'EUR',
            ),
        ).toEqual({ count: 2, total: { status: 'missing' } });
    });
});
