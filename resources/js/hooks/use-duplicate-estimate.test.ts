import {
    groupByTarget,
    useDuplicateEstimate,
} from '@/hooks/use-duplicate-estimate';
import { type BuiltImport } from '@/lib/full-import-plan';
import { type TransactionPayloadRow } from '@/types/full-import';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const checkExistingTransactions = vi.hoisted(() => vi.fn());

vi.mock('@/lib/full-import-api', () => ({ checkExistingTransactions }));

function row(accountKey: string, amount: number): TransactionPayloadRow {
    return {
        account_key: accountKey,
        date: '2026-09-01',
        amount,
        description: 'Coffee',
        notes: null,
        category_key: null,
        external_id: null,
        currency_code: null,
    };
}

function built(transactions: TransactionPayloadRow[]): BuiltImport {
    return {
        payload: {} as BuiltImport['payload'],
        transactions,
        balances: [],
    };
}

const transactions = [
    row('a0', -100),
    row('a1', -200),
    row('a0', -300),
    row('a2', -1),
];
const targets = { a0: 'own-bbva', a1: 'own-bbva' };

describe('groupByTarget', () => {
    it('groups the rows by the user account they go into, leaving new accounts out', () => {
        const groups = groupByTarget(transactions, targets);

        expect([...groups.keys()]).toEqual(['own-bbva']);
        expect(groups.get('own-bbva')?.keys).toEqual(['a0', 'a1', 'a0']);
    });
});

describe('useDuplicateEstimate', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        checkExistingTransactions.mockReset();
    });

    afterEach(() => vi.useRealTimers());

    it('counts what each account already holds, once per plan', async () => {
        checkExistingTransactions.mockResolvedValue([true, false, true]);
        const plan = built(transactions);
        const { result } = renderHook(() =>
            useDuplicateEstimate(plan, targets),
        );

        expect(result.current).toEqual({ estimate: null, checking: true });

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(result.current).toEqual({
            estimate: { existing: 2, byAccount: { a0: 2 } },
            checking: false,
        });
        expect(checkExistingTransactions).toHaveBeenCalledTimes(1);
        expect(checkExistingTransactions).toHaveBeenCalledWith('own-bbva', [
            transactions[0],
            transactions[1],
            transactions[2],
        ]);
    });

    it('says nothing when no row goes into an existing account', () => {
        const { result } = renderHook(() =>
            useDuplicateEstimate(built(transactions), {}),
        );

        expect(result.current).toEqual({ estimate: null, checking: false });
        expect(checkExistingTransactions).not.toHaveBeenCalled();
    });

    it('falls back to no estimate when the check fails', async () => {
        checkExistingTransactions.mockRejectedValue(new Error('offline'));
        const plan = built(transactions);
        const { result } = renderHook(() =>
            useDuplicateEstimate(plan, targets),
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(300);
        });

        expect(result.current).toEqual({ estimate: null, checking: false });
    });
});
