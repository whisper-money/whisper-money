import { type Transaction } from '@/types/transaction';
import { renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    useInMemoryDaySummaries,
    useServerDaySummaries,
} from './use-day-summaries';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { currency_code: 'EUR' } } } }),
}));

vi.mock('@/hooks/use-amounts-in-user-currency', () => ({
    useAmountsInUserCurrency: () => (transaction: Transaction) =>
        transaction.currency_code === 'EUR' ? transaction.amount : null,
}));

function transaction(
    id: string,
    transaction_date: string,
    amount: number,
    currency_code = 'EUR',
): Transaction {
    return { id, amount, currency_code, transaction_date } as Transaction;
}

describe('useInMemoryDaySummaries', () => {
    it('counts and adds up every row of a day, not only the drawn ones', () => {
        const rows = [
            transaction('a', '2026-10-26', -1000),
            transaction('b', '2026-10-26', -500),
            transaction('c', '2026-10-25', 300),
        ];
        const { result } = renderHook(() => useInMemoryDaySummaries(rows));

        expect(result.current('2026-10-26')).toEqual({
            count: 2,
            total: { status: 'ready', amount: -1500 },
        });
        expect(result.current('2026-10-25')).toEqual({
            count: 1,
            total: { status: 'ready', amount: 300 },
        });
    });

    it('leaves a day total out when one of its rows has no rate', () => {
        const rows = [
            transaction('a', '2026-10-26', -1000),
            transaction('b', '2026-10-26', -500, 'GBP'),
        ];
        const { result } = renderHook(() => useInMemoryDaySummaries(rows));

        expect(result.current('2026-10-26').total).toEqual({
            status: 'missing',
        });
    });
});

describe('useServerDaySummaries', () => {
    it('reads each day off the server and moves it by local edits', () => {
        const served = [
            transaction('a', '2026-10-26', -1000),
            transaction('b', '2026-10-25', -200),
        ];
        const { result } = renderHook(() =>
            useServerDaySummaries({
                dayTotals: {
                    '2026-10-26': { count: 12, total: -9000 },
                    '2026-10-25': { count: 1, total: -200 },
                },
                served,
                loaded: [served[0]],
            }),
        );

        expect(result.current('2026-10-26')).toEqual({
            count: 12,
            total: { status: 'ready', amount: -9000 },
        });
        expect(result.current('2026-10-25')).toEqual({
            count: 0,
            total: { status: 'ready', amount: 0 },
        });
    });
});
