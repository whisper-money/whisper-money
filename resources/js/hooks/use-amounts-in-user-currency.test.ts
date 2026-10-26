import { type Transaction } from '@/types/transaction';
import { renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAmountsInUserCurrency } from './use-amounts-in-user-currency';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { currency_code: 'EUR' } } } }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

const post = vi.mocked(axios.post);

function transaction(
    id: string,
    amount: number,
    currency_code: string,
    transaction_date = '2026-10-25',
): Transaction {
    return { id, amount, currency_code, transaction_date } as Transaction;
}

beforeEach(() => {
    post.mockReset();
});

describe('useAmountsInUserCurrency', () => {
    it('keeps rows in the user currency in the browser', () => {
        const rows = [
            transaction('a', -1000, 'EUR'),
            transaction('b', 5, 'eur'),
        ];
        const { result } = renderHook(() => useAmountsInUserCurrency(rows));

        expect(result.current(rows[0])).toBe(-1000);
        expect(result.current(rows[1])).toBe(5);
        expect(post).not.toHaveBeenCalled();
    });

    it('asks for every foreign row in one request and keeps the answer', async () => {
        post.mockResolvedValue({ data: { amounts: [-500, null] } });
        const rows = [
            transaction('a', -1000, 'EUR'),
            transaction('b', -1000, 'USD'),
            transaction('c', -2000, 'GBP', '2026-10-24'),
        ];

        const { result, rerender } = renderHook(
            ({ list }) => useAmountsInUserCurrency(list),
            { initialProps: { list: rows } },
        );

        expect(result.current(rows[1])).toBeUndefined();
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toEqual({
            items: [
                { from: 'USD', amount: -1000, date: '2026-10-25' },
                { from: 'GBP', amount: -2000, date: '2026-10-24' },
            ],
        });

        await waitFor(() => expect(result.current(rows[1])).toBe(-500));
        expect(result.current(rows[2])).toBeNull();

        // Already answered: a new list holding the same rows asks nothing.
        rerender({ list: [...rows] });
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('treats a failed request as amounts it could not convert', async () => {
        post.mockRejectedValue(new Error('offline'));
        const rows = [transaction('b', -1000, 'USD')];

        const { result } = renderHook(() => useAmountsInUserCurrency(rows));

        await waitFor(() => expect(result.current(rows[0])).toBeNull());
    });

    it('asks again for rows whose request failed once the list changes', async () => {
        post.mockRejectedValueOnce(new Error('offline'));
        post.mockResolvedValueOnce({ data: { amounts: [-500] } });
        const rows = [transaction('b', -1000, 'USD')];

        const { result, rerender } = renderHook(
            ({ list }) => useAmountsInUserCurrency(list),
            { initialProps: { list: rows } },
        );

        await waitFor(() => expect(result.current(rows[0])).toBeNull());

        rerender({ list: [...rows] });

        await waitFor(() => expect(result.current(rows[0])).toBe(-500));
        expect(post).toHaveBeenCalledTimes(2);
    });
});
