import { type TransactionFilters } from '@/types/transaction';
import { toLocalDate } from '@/utils/date';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { transactionSyncService } from './transaction-sync';

const dbMock = vi.hoisted(() => ({
    transactions: {
        delete: vi.fn(async () => undefined),
    },
    sync_metadata: { delete: vi.fn(), get: vi.fn(), put: vi.fn() },
}));

const axiosMock = vi.hoisted(() => ({
    delete: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(async () => ({ data: { count: 1 } })),
    post: vi.fn(async () => ({ data: {} })),
}));

// Keep the real withDb (reads globalThis live); swap only the Dexie-backed db.
vi.mock('@/lib/dexie-db', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/dexie-db')>();
    return { ...actual, db: dbMock };
});

vi.mock('axios', () => ({ default: axiosMock }));

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
        const filters = {
            dateFrom: picked,
            dateTo: picked,
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
        } satisfies TransactionFilters;

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

describe('transactionSyncService external ids', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('sends the external id of a created transaction', async () => {
        axiosMock.post.mockResolvedValueOnce({
            data: { data: { id: 't1', transaction_date: '2026-03-02' } },
        });

        await transactionSyncService.create({
            external_transaction_id: 'tr-1',
        } as Parameters<typeof transactionSyncService.create>[0]);

        expect(axiosMock.post).toHaveBeenCalledWith(
            expect.any(String),
            expect.objectContaining({ external_transaction_id: 'tr-1' }),
        );
    });

    it('sends the external id of a row to the duplicate check only when it has one', async () => {
        axiosMock.post.mockResolvedValueOnce({
            data: { duplicates: [true, false] },
        });

        const flags = await transactionSyncService.checkDuplicates('a1', [
            {
                transaction_date: '2026-03-02',
                amount: -100,
                description: 'Buy',
                external_id: 'tr-1',
            },
            {
                transaction_date: '2026-03-02',
                amount: -100,
                description: 'Coffee',
            },
        ]);

        expect(flags).toEqual([true, false]);
        expect(axiosMock.post).toHaveBeenCalledWith(
            '/api/transactions/check-duplicates',
            {
                account_id: 'a1',
                transactions: [
                    {
                        transaction_date: '2026-03-02',
                        amount: -100,
                        description: 'Buy',
                        external_id: 'tr-1',
                    },
                    {
                        transaction_date: '2026-03-02',
                        amount: -100,
                        description: 'Coffee',
                    },
                ],
            },
        );
    });
});
