import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { describe, expect, it, vi } from 'vitest';
import {
    type RenderWidgetTransactionTable,
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
    const host = { accounts: {}, categories: [], labels: [] };
    const apiRow = (id: string, overrides: Record<string, unknown> = {}) => ({
        ...row(id),
        description: `Row ${id}`,
        amount: -100,
        currency: 'EUR',
        ...overrides,
    });
    const layout = {
        pageSize: null,
        hideAccount: false,
        displayRow: (transaction: DecryptedTransaction) => transaction,
        badgeOf: () => undefined,
        hiddenLabelId: undefined,
        showDayTotals: true,
    };

    function handed(
        data: Record<string, unknown>,
        transactionIds: string[],
    ): DecryptedTransaction[] {
        const render = vi.fn<RenderWidgetTransactionTable>(() => null);

        scopedTransactionTable(
            render,
            data,
            host,
        )?.({
            ...layout,
            transactionIds,
        });

        return render.mock.calls[0][0].transactions;
    }

    it('is null where the host offers no table', () => {
        expect(
            scopedTransactionTable(undefined, { tx: [row('tx-1')] }, host),
        ).toBe(null);
    });

    it('builds each row from the host data, the same list each time', () => {
        const render = vi.fn<RenderWidgetTransactionTable>(() => null);
        const scoped = scopedTransactionTable(
            render,
            { tx: { data: [apiRow('tx-1')] } },
            host,
        );

        scoped?.({ ...layout, transactionIds: ['tx-1', 'someone-elses'] });
        scoped?.({ ...layout, transactionIds: ['tx-1', 'someone-elses'] });

        const [first, second] = render.mock.calls.map(
            ([props]) => props.transactions,
        );

        expect(first.map((transaction) => transaction.id)).toEqual(['tx-1']);
        expect(first[0]).toMatchObject({
            description: 'Row tx-1',
            amount: -100,
        });
        // The host's table reloads its rows on a new list: none for nothing.
        expect(second).toBe(first);
    });

    it('drops made-up ids and repeats', () => {
        expect(
            handed({ tx: [apiRow('tx-1')] }, ['made-up', 'tx-1', 'tx-1']).map(
                (transaction) => transaction.id,
            ),
        ).toEqual(['tx-1']);
    });

    it("never takes a row's amount or text from the widget", () => {
        const [held] = handed({ tx: [apiRow('tx-1', { amount: -4200 })] }, [
            'tx-1',
        ]);

        expect(held).toMatchObject({ amount: -4200, description: 'Row tx-1' });
    });

    it('keeps the order the host data gives, newest first', () => {
        const ids = handed(
            {
                tx: [
                    apiRow('old', { transaction_date: '2026-09-01' }),
                    apiRow('new', { transaction_date: '2026-10-01' }),
                ],
            },
            ['old', 'new'],
        ).map((transaction) => transaction.id);

        expect(ids).toEqual(['new', 'old']);
    });

    it("hands the table only the user's own categories and labels", () => {
        const render = vi.fn<RenderWidgetTransactionTable>(() => null);
        const own = { id: 'cat-own', name: 'Own' } as Category;

        scopedTransactionTable(
            render,
            {
                tx: [
                    apiRow('tx-1', {
                        category: { id: 'cat-foreign', name: 'Foreign' },
                    }),
                ],
            },
            { accounts: {}, categories: [own], labels: [] },
        )?.({ ...layout, transactionIds: ['tx-1'] });

        const props = render.mock.calls[0][0];

        expect(props.categories).toEqual([own]);
        expect(props.labels).toEqual([]);
        expect(props.transactions[0].category_id).toBe('cat-foreign');
    });

    it('is not fooled by a widget that patched the shared statics', () => {
        // Captured at load: even unfrozen, a swap after it changes nothing.
        const isArray = Array.isArray;
        const values = Object.values;

        try {
            Array.isArray = (() => false) as unknown as typeof isArray;
            Object.values = () => [];

            expect([...transactionIdsIn({ tx: [row('tx-1')] })]).toEqual([
                'tx-1',
            ]);
        } finally {
            Array.isArray = isArray;
            Object.values = values;
        }
    });
});
