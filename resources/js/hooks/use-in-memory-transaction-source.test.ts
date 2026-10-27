import { type Transaction, type TransactionFilters } from '@/types/transaction';
import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useInMemoryTransactionSource } from './use-in-memory-transaction-source';

vi.mock('@/hooks/use-day-summaries', () => ({
    useInMemoryDaySummaries: (rows: Transaction[]) => (date: string) => ({
        count: rows.filter((row) => row.transaction_date === date).length,
        total: { status: 'pending' },
    }),
}));

function transaction(
    id: string,
    overrides: Partial<Transaction> = {},
): Transaction {
    return {
        id,
        description: id,
        notes: null,
        amount: -1000,
        currency_code: 'EUR',
        transaction_date: '2026-10-01',
        account_id: 'checking',
        category_id: null,
        creditor_name: null,
        debtor_name: null,
        ...overrides,
    } as unknown as Transaction;
}

const ROWS = [
    transaction('a', { transaction_date: '2026-10-03', creditor_name: 'Zed' }),
    transaction('b', {
        transaction_date: '2026-10-01',
        description: 'Coffee',
        creditor_name: 'Amy',
    }),
    transaction('c', {
        transaction_date: '2026-10-02',
        account_id: 'savings',
    }),
];

describe('useInMemoryTransactionSource', () => {
    beforeEach(() => {
        vi.stubGlobal('requestAnimationFrame', (callback: () => void) => {
            callback();
            return 0;
        });
    });

    afterEach(() => vi.unstubAllGlobals());

    it('draws the newest rows first, a page at a time', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({ transactions: ROWS, pageSize: 2 }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.rows.map((row) => row.id)).toEqual(['a', 'c']);
        expect(result.current.heldRows).toHaveLength(3);
        expect(result.current.pagination).toMatchObject({
            displayedCount: 2,
            total: 3,
            hasMore: true,
            loadsOnScroll: false,
        });

        act(() => result.current.pagination.loadMore());

        expect(result.current.rows.map((row) => row.id)).toEqual([
            'a',
            'c',
            'b',
        ]);
        expect(result.current.pagination.hasMore).toBe(false);
    });

    it('holds the rows from the first render, so it never draws empty first', () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({ transactions: ROWS }),
        );

        expect(result.current.isLoading).toBe(false);
        expect(result.current.heldRows).toHaveLength(3);
    });

    it('takes the rows the page hands over next', () => {
        const { result, rerender } = renderHook(
            ({ transactions }) =>
                useInMemoryTransactionSource({ transactions }),
            { initialProps: { transactions: ROWS } },
        );

        rerender({ transactions: [transaction('z')] });

        expect(result.current.heldRows.map((row) => row.id)).toEqual(['z']);
    });

    it('keeps an account page to its account', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                accountId: 'checking',
            }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.filters.accountIds).toEqual(['checking']);
        expect(result.current.rows.map((row) => row.id)).toEqual(['a', 'b']);
    });

    it('orders the rows of one day by id, newest first, as the server does', () => {
        const sameDay = ['0b', '0c', '0a'].map((id) => transaction(id));
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({ transactions: sameDay }),
        );

        expect(result.current.rows.map((row) => row.id)).toEqual([
            '0c',
            '0b',
            '0a',
        ]);
    });

    it('searches in the browser, back to the first page', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({ transactions: ROWS, pageSize: 1 }),
        );
        await waitFor(() => expect(result.current.isLoading).toBe(false));
        act(() => result.current.pagination.loadMore());
        expect(result.current.pagination.displayedCount).toBe(2);

        act(() =>
            result.current.onFiltersChange({
                ...result.current.filters,
                searchText: 'coffee',
            }),
        );

        await waitFor(() =>
            expect(result.current.rows.map((row) => row.id)).toEqual(['b']),
        );
        expect(result.current.pagination.displayedCount).toBe(1);
    });

    it('counts each day over every row the filters keep, drawn or not', async () => {
        const sameDay = [transaction('a'), transaction('b'), transaction('c')];
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: sameDay,
                pageSize: 1,
            }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.rows).toHaveLength(1);
        expect(result.current.daySummaryOf('2026-10-01').count).toBe(3);
    });

    it('counts what a filter set would keep without applying it', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                accountId: 'checking',
            }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        const count = (changes: Partial<TransactionFilters>) =>
            result.current.countMatching(
                { ...result.current.filters, ...changes },
                new AbortController().signal,
            );

        // Scoped to its account: "c" is in savings.
        await expect(count({})).resolves.toBe(2);
        await expect(count({ searchText: 'coffee' })).resolves.toBe(1);
        expect(result.current.rows).toHaveLength(2);
    });
});
