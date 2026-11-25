import { type TransactionFilters } from '@/types/transaction';
import { toLocalDate } from '@/utils/date';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    transactionSyncService,
    transformTransactionFromServer,
} from './transaction-sync';

const dbMock = vi.hoisted(() => ({
    transactions: {
        delete: vi.fn(async () => undefined),
        bulkDelete: vi.fn(async () => undefined),
        put: vi.fn(async () => undefined),
    },
    sync_metadata: { delete: vi.fn(), get: vi.fn(), put: vi.fn() },
}));

const axiosMock = vi.hoisted(() => ({
    delete: vi.fn(
        async (): Promise<{ data: Record<string, unknown> }> => ({
            data: {},
        }),
    ),
    post: vi.fn(
        async (): Promise<{ data: Record<string, unknown> }> => ({
            data: {},
        }),
    ),
    patch: vi.fn(
        async (): Promise<{ data: Record<string, unknown> }> => ({
            data: { updated_count: 2, skipped_split_count: 1 },
        }),
    ),
}));

// Keep the real withDb (reads globalThis live); swap only the Dexie-backed db.
vi.mock('@/lib/dexie-db', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/dexie-db')>();
    return { ...actual, db: dbMock };
});

vi.mock('axios', () => ({ default: axiosMock }));

function bulkFilters(
    overrides: Partial<TransactionFilters> = {},
): TransactionFilters {
    return {
        dateFrom: null,
        dateTo: null,
        amountMin: null,
        amountMax: null,
        categoryIds: [],
        categoryTypes: [],
        accountIds: [],
        labelIds: [],
        creditorName: '',
        debtorName: '',
        searchText: '',
        aiCategorizedOnly: false,
        ...overrides,
    };
}

describe('transactionSyncService.update', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('persists the authoritative server response in IndexedDB before returning it', async () => {
        vi.stubGlobal('indexedDB', {} as IDBFactory);
        axiosMock.patch.mockResolvedValueOnce({
            data: {
                data: {
                    id: 'txn-1',
                    transaction_date: '2026-07-21T00:00:00.000Z',
                    updated_at: '2026-07-21T18:00:00.000Z',
                    labels: [{ id: 'label-1' }],
                    splits: [{ id: 'split-1', amount: -1000 }],
                },
                learned_rule: null,
            },
        });

        const updated = await transactionSyncService.update('txn-1', {
            amount: -1000,
        });

        expect(updated).toMatchObject({
            id: 'txn-1',
            transaction_date: '2026-07-21',
            updated_at: '2026-07-21T18:00:00.000Z',
            label_ids: ['label-1'],
            splits: [{ id: 'split-1', amount: -1000 }],
        });
        expect(dbMock.transactions.put).toHaveBeenCalledWith(
            expect.objectContaining({
                id: 'txn-1',
                updated_at: '2026-07-21T18:00:00.000Z',
                label_ids: ['label-1'],
            }),
        );
    });
});

describe('transactionSyncService.delete', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('deletes via the API but skips the cache eviction when IndexedDB is missing', async () => {
        vi.stubGlobal('indexedDB', undefined);

        await expect(
            transactionSyncService.delete('txn-1'),
        ).resolves.toBeUndefined();

        expect(axiosMock.delete).toHaveBeenCalledWith('/transactions/txn-1', {
            data: undefined,
        });
        expect(dbMock.transactions.delete).not.toHaveBeenCalled();
    });

    it('deletes via the API and evicts the cache when IndexedDB is available', async () => {
        vi.stubGlobal('indexedDB', {} as IDBFactory);

        await transactionSyncService.delete('txn-1');

        expect(axiosMock.delete).toHaveBeenCalledWith('/transactions/txn-1', {
            data: undefined,
        });
        expect(dbMock.transactions.delete).toHaveBeenCalledWith('txn-1');
    });
});

// The sync endpoint never announces deletions, so whatever a split or a merge
// takes away has to leave the offline cache here.
describe('transactionSyncService split and unsplit', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal('indexedDB', {} as IDBFactory);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('evicts the original a split replaced, and returns its parts', async () => {
        const splits = [
            { amount: -3000, category_id: null, label_ids: [] },
            { amount: -2000, category_id: 'food', label_ids: ['label-1'] },
        ];
        axiosMock.post.mockResolvedValueOnce({
            data: {
                data: [
                    { id: 'part-1', split_parent_id: 'txn-1', labels: [] },
                    {
                        id: 'part-2',
                        split_parent_id: 'txn-1',
                        labels: [{ id: 'label-1' }],
                    },
                ],
            },
        });

        const parts = await transactionSyncService.split('txn-1', splits);

        expect(axiosMock.post).toHaveBeenCalledWith(
            '/transactions/txn-1/split',
            { splits },
        );
        expect(dbMock.transactions.delete).toHaveBeenCalledOnce();
        expect(dbMock.transactions.delete).toHaveBeenCalledWith('txn-1');
        expect(dbMock.transactions.bulkDelete).not.toHaveBeenCalled();
        expect(parts.map((part) => part.id)).toEqual(['part-1', 'part-2']);
    });

    it('evicts every part a merge took away, the one it went through once', async () => {
        axiosMock.delete.mockResolvedValueOnce({
            data: { data: { id: 'txn-1', labels: [] } },
        });

        const original = await transactionSyncService.unsplit('part-1', [
            'part-1',
            'part-2',
        ]);

        expect(axiosMock.delete).toHaveBeenCalledWith(
            '/transactions/part-1/split',
        );
        expect(dbMock.transactions.bulkDelete).toHaveBeenCalledOnce();
        expect(dbMock.transactions.bulkDelete).toHaveBeenCalledWith([
            'part-1',
            'part-2',
        ]);
        expect(original.id).toBe('txn-1');
    });

    it('evicts at least the part it went through when the siblings are unknown', async () => {
        axiosMock.delete.mockResolvedValueOnce({
            data: { data: { id: 'txn-1', labels: [] } },
        });

        await transactionSyncService.unsplit('part-1');

        expect(dbMock.transactions.bulkDelete).toHaveBeenCalledOnce();
        expect(dbMock.transactions.bulkDelete).toHaveBeenCalledWith(['part-1']);
    });

    it('leaves the cache alone when the server refuses the split', async () => {
        axiosMock.post.mockRejectedValueOnce(new Error('422'));

        await expect(transactionSyncService.split('txn-1', [])).rejects.toThrow(
            '422',
        );

        expect(dbMock.transactions.delete).not.toHaveBeenCalled();
    });
});

describe('transactionSyncService bulk updates', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('persists authoritative bulk records before returning them', async () => {
        vi.stubGlobal('indexedDB', {} as IDBFactory);
        axiosMock.patch.mockResolvedValueOnce({
            data: {
                updated_count: 1,
                skipped_split_count: 1,
                updated_ids: ['tx-1'],
                skipped_split_ids: ['tx-2'],
                transactions: [
                    {
                        id: 'tx-1',
                        transaction_date: '2026-07-21T00:00:00.000Z',
                        updated_at: '2026-07-21T18:00:00.123456Z',
                        labels: [{ id: 'label-1' }],
                        splits: [],
                    },
                ],
            },
        });

        const result = await transactionSyncService.updateMany(
            ['tx-1', 'tx-2'],
            { category_id: 'category-1' },
        );

        expect(result.transactions[0]).toMatchObject({
            id: 'tx-1',
            transaction_date: '2026-07-21',
            label_ids: ['label-1'],
        });
        expect(dbMock.transactions.put).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'tx-1', label_ids: ['label-1'] }),
        );
    });

    it('sends a removal of every label for explicit ids', async () => {
        await transactionSyncService.updateMany(['tx-1'], {
            remove_all_labels: true,
        });

        expect(axiosMock.patch).toHaveBeenLastCalledWith(
            '/transactions/bulk',
            expect.objectContaining({
                transaction_ids: ['tx-1'],
                remove_all_labels: true,
            }),
        );
    });

    it('returns updated and skipped split counts for explicit ids', async () => {
        await expect(
            transactionSyncService.updateMany(['tx-1', 'tx-2', 'tx-3'], {
                category_id: 'category-1',
            }),
        ).resolves.toMatchObject({
            updated_count: 2,
            skipped_split_count: 1,
        });
    });

    it('serializes filter dates as local calendar dates', async () => {
        const previousTimezone = process.env.TZ;
        process.env.TZ = 'Pacific/Kiritimati';

        try {
            const dateFrom = new Date(2026, 6, 1);
            const dateTo = new Date(2026, 6, 31);

            await transactionSyncService.updateByFilters(
                bulkFilters({ dateFrom, dateTo }),
                { category_id: 'category-1' },
            );

            expect(axiosMock.patch).toHaveBeenLastCalledWith(
                '/transactions/bulk',
                expect.objectContaining({
                    filters: expect.objectContaining({
                        date_from: '2026-07-01',
                        date_to: '2026-07-31',
                    }),
                }),
            );
        } finally {
            if (previousTimezone === undefined) {
                delete process.env.TZ;
            } else {
                process.env.TZ = previousTimezone;
            }
        }
    });

    it('sends a removal of every label for filters', async () => {
        await transactionSyncService.updateByFilters(bulkFilters(), {
            remove_all_labels: true,
        });

        expect(axiosMock.patch).toHaveBeenLastCalledWith(
            '/transactions/bulk',
            expect.objectContaining({
                filters: {},
                remove_all_labels: true,
            }),
        );
    });

    it('sends the Uncategorized filter as the list reads it', async () => {
        await transactionSyncService.updateByFilters(
            bulkFilters({ categoryIds: ['uncategorized'] }),
            { label_ids: ['label-1'] },
        );

        expect(axiosMock.patch).toHaveBeenLastCalledWith(
            '/transactions/bulk',
            expect.objectContaining({
                filters: expect.objectContaining({
                    category_ids: ['uncategorized'],
                }),
            }),
        );
    });

    it('returns updated and skipped split counts for filters', async () => {
        await expect(
            transactionSyncService.updateByFilters(
                bulkFilters({ dateFrom: new Date('2026-07-01T00:00:00Z') }),
                { category_id: 'category-1' },
            ),
        ).resolves.toMatchObject({
            updated_count: 2,
            skipped_split_count: 1,
        });
    });

    it('narrows a select-all edit to the cashflow block the list shows', async () => {
        await transactionSyncService.updateByFilters(
            bulkFilters({
                dateFrom: toLocalDate('2026-10-01'),
                dateTo: toLocalDate('2026-10-31'),
                cashflowBlock: 'fixed',
                aiCategorizedOnly: true,
            }),
            { category_id: 'category-1' },
        );

        expect(axiosMock.patch).toHaveBeenLastCalledWith(
            '/transactions/bulk',
            expect.objectContaining({
                filters: {
                    date_from: '2026-10-01',
                    date_to: '2026-10-31',
                    cashflow_block: 'fixed',
                    category_source: 'ai',
                },
            }),
        );
    });

    it.each([
        ['the start date', { dateTo: toLocalDate('2026-10-31') }],
        ['the end date', { dateFrom: toLocalDate('2026-10-01') }],
    ])(
        'drops the cashflow block without %s, as the list does',
        async (_missing, dates) => {
            await transactionSyncService.updateByFilters(
                bulkFilters({ ...dates, cashflowBlock: 'fixed' }),
                { category_id: 'category-1' },
            );

            const [, body] = axiosMock.patch.mock.lastCall as unknown as [
                string,
                { filters: Record<string, unknown> },
            ];
            expect(body.filters).not.toHaveProperty('cashflow_block');
        },
    );
});

describe('transaction sync transformation', () => {
    it('keeps embedded split lines while deriving label ids', () => {
        const splits = [
            {
                id: 'split-1',
                category_id: 'food',
                amount: -6000,
                position: 0,
                category: { id: 'food', name: 'Food' },
            },
            {
                id: 'split-2',
                category_id: 'home',
                amount: -4000,
                position: 1,
                category: { id: 'home', name: 'Home' },
            },
        ];

        expect(
            transformTransactionFromServer({
                id: 'transaction-1',
                transaction_date: '2026-07-18T00:00:00.000Z',
                labels: [{ id: 'label-1' }],
                splits,
                is_split: true,
                split_count: 2,
            }),
        ).toMatchObject({
            transaction_date: '2026-07-18',
            label_ids: ['label-1'],
            splits,
            is_split: true,
            split_count: 2,
        });
    });
});

/**
 * The request half of the date filter: `toISOString()` here shifted the picked
 * day by the UTC offset, which used to cancel out an equal and opposite bug in
 * the input. Both halves are asserted, in their own suites, so neither can go
 * back to compensating for the other.
 */
describe.each([
    'America/Argentina/Buenos_Aires',
    'Europe/Madrid',
    'Pacific/Auckland',
])('transactionSyncService.updateByFilters in %s', (timeZone) => {
    const originalTimeZone = process.env.TZ;

    beforeEach(() => {
        vi.clearAllMocks();
        process.env.TZ = timeZone;
    });

    afterEach(() => {
        process.env.TZ = originalTimeZone;
    });

    it('sends the calendar day the user picked, not its UTC day', async () => {
        const picked = toLocalDate('2026-09-09');
        const filters = bulkFilters({ dateFrom: picked, dateTo: picked });

        await transactionSyncService.updateByFilters(filters, {
            category_id: 'cat-1',
        });

        expect(axiosMock.patch).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({
                filters: { date_from: '2026-09-09', date_to: '2026-09-09' },
            }),
        );
    });
});
