import { describe, expect, it, vi } from 'vitest';
import {
    scopedTransactionOpener,
    transactionIdsIn,
} from './transaction-opener';

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

describe('scopedTransactionOpener', () => {
    it('is null where the host opens nothing', () => {
        expect(scopedTransactionOpener(undefined, { tx: [row('tx-1')] })).toBe(
            null,
        );
    });

    it('opens only the transactions of the widget data', () => {
        const open = vi.fn();
        const scoped = scopedTransactionOpener(open, {
            tx: { data: [row('tx-1')] },
        });

        scoped?.('tx-1');
        scoped?.('someone-elses');

        expect(open).toHaveBeenCalledTimes(1);
        expect(open).toHaveBeenCalledWith('tx-1');
    });
});
