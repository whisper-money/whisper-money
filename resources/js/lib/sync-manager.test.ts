import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionSyncManager } from './sync-manager';

const dbMock = vi.hoisted(() => ({
    transactions: {
        toArray: vi.fn(),
        get: vi.fn(),
        clear: vi.fn(),
        bulkPut: vi.fn(),
        where: vi.fn(() => ({
            equals: () => ({ toArray: vi.fn(async () => []) }),
        })),
    },
    sync_metadata: {
        get: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

// Keep the real isIndexedDbAvailable + withDb (they read globalThis live); only
// swap the Dexie-backed `db` for spies so no real IndexedDB is needed.
vi.mock('./dexie-db', async (importOriginal) => {
    const actual = await importOriginal<typeof import('./dexie-db')>();
    return { ...actual, db: dbMock };
});

vi.mock('axios', () => ({ default: { get: vi.fn() } }));

function makeManager(): TransactionSyncManager {
    return new TransactionSyncManager({ endpoint: '/api/sync/transactions' });
}

describe('TransactionSyncManager without IndexedDB', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal('indexedDB', undefined);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('clearAll resolves without touching the database or throwing', async () => {
        await expect(makeManager().clearAll()).resolves.toBeUndefined();
        expect(dbMock.transactions.clear).not.toHaveBeenCalled();
        expect(dbMock.sync_metadata.delete).not.toHaveBeenCalled();
    });

    it('sync returns a skipped result without a server round-trip', async () => {
        const axios = (await import('axios')).default;

        const result = await makeManager().sync();

        expect(result).toEqual({
            success: true,
            inserted: 0,
            updated: 0,
            errors: [],
        });
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('reads degrade to empty values instead of throwing', async () => {
        const manager = makeManager();

        await expect(manager.getAll()).resolves.toEqual([]);
        await expect(manager.getById('id-1')).resolves.toBeNull();
        await expect(manager.getByAccountId('acc-1')).resolves.toEqual([]);
        await expect(manager.getLastSyncTime()).resolves.toBeNull();
        await expect(manager.setLastSyncTime('now')).resolves.toBeUndefined();

        expect(dbMock.transactions.toArray).not.toHaveBeenCalled();
        expect(dbMock.sync_metadata.get).not.toHaveBeenCalled();
    });
});

describe('TransactionSyncManager with IndexedDB', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal('indexedDB', {} as IDBFactory);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('clearAll clears the transactions and sync metadata', async () => {
        await makeManager().clearAll();

        expect(dbMock.transactions.clear).toHaveBeenCalledTimes(1);
        expect(dbMock.sync_metadata.delete).toHaveBeenCalledWith(
            'last_sync_transactions',
        );
    });

    it('getAll passes through the stored transactions', async () => {
        const stored = [{ id: 't1' }];
        dbMock.transactions.toArray.mockResolvedValueOnce(stored);

        await expect(makeManager().getAll()).resolves.toBe(stored);
    });
});
