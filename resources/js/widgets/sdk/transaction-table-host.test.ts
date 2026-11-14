import { type ServerTransaction } from '@/types/transaction';
import { describe, expect, it, vi } from 'vitest';
import {
    scopedTransactionTable,
    transactionIdsIn,
} from './transaction-table-host';

const row = (id: string) => ({ id, transaction_date: '2026-10-01' });

describe('transactionIdsIn', () => {
    it('reads the rows of listed and paginate "all" answers', () => {
        const ids = transactionIdsIn({
            listed: [row('tx-1'), row('tx-2')],
            all: { data: [row('tx-3')], truncated: false },
        });

        expect([...ids]).toEqual(['tx-1', 'tx-2', 'tx-3']);
    });

    it('ignores what is not a transaction', () => {
        const ids = transactionIdsIn({
            total: 1200,
            accounts: [{ id: 'acc-1', name: 'Nómina' }],
            broken: [null, { id: 7, transaction_date: '2026-10-01' }],
        });

        expect(ids.size).toBe(0);
    });
});

describe('scopedTransactionTable', () => {
    it('is null where the host offers no table', () => {
        expect(scopedTransactionTable(undefined, { tx: [row('tx-1')] })).toBe(
            null,
        );
    });

    it('hands the host only the transactions of the widget data, the same list each time', () => {
        const render = vi.fn();
        const scoped = scopedTransactionTable(render, {
            tx: { data: [row('tx-1')] },
        });
        const props = {
            transactions: [
                { id: 'tx-1' },
                { id: 'someone-elses' },
            ] as ServerTransaction[],
            pageSize: null,
            hideAccount: false,
            displayRow: (transaction: ServerTransaction) => transaction,
            badgeOf: () => undefined,
            hiddenLabelId: undefined,
            showDayTotals: true,
            categories: [],
            labels: [],
        };

        scoped?.(props);
        scoped?.({ ...props });

        const [first, second] = render.mock.calls.map(
            ([handed]) => handed.transactions as ServerTransaction[],
        );

        expect(first.map((transaction) => transaction.id)).toEqual(['tx-1']);
        // The host's table reloads its rows on a new list: none for nothing.
        expect(second).toBe(first);
    });
});
