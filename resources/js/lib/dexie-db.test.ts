import type { Transaction } from '@/types/transaction';
import type { UUID } from '@/types/uuid';
import { describe, expect, it, vi } from 'vitest';
import {
    ensureTransactionsTable,
    type TransactionDatabaseLike,
    type TransactionStoreLike,
} from './dexie-db';

function createTransactionStore(): TransactionStoreLike {
    return {
        toArray: vi.fn<() => Promise<Transaction[]>>().mockResolvedValue([]),
        get: vi
            .fn<(key: UUID) => Promise<Transaction | undefined>>()
            .mockResolvedValue(undefined),
        where: vi
            .fn<
                (index: string) => {
                    equals: (key: UUID) => {
                        toArray: () => Promise<Transaction[]>;
                    };
                }
            >()
            .mockReturnValue({
                equals: vi
                    .fn<
                        (key: UUID) => { toArray: () => Promise<Transaction[]> }
                    >()
                    .mockReturnValue({
                        toArray: vi
                            .fn<() => Promise<Transaction[]>>()
                            .mockResolvedValue([]),
                    }),
            }),
        bulkPut: vi
            .fn<(records: readonly Transaction[]) => Promise<unknown>>()
            .mockResolvedValue(undefined),
        clear: vi.fn<() => Promise<void>>().mockResolvedValue(undefined),
        delete: vi
            .fn<(key: UUID) => Promise<void>>()
            .mockResolvedValue(undefined),
    };
}

function createDatabase(
    overrides: Partial<TransactionDatabaseLike> = {},
): TransactionDatabaseLike {
    return {
        open: vi.fn<() => Promise<unknown>>().mockResolvedValue(undefined),
        close: vi.fn<() => void>(),
        delete: vi.fn<() => Promise<void>>().mockResolvedValue(undefined),
        tables: [{ name: 'transactions' }],
        transactions: createTransactionStore(),
        ...overrides,
    };
}

describe('ensureTransactionsTable', () => {
    it('rebuilds the local cache when IndexedDB fails to open', async () => {
        const database = createDatabase();
        const open = vi
            .fn<() => Promise<unknown>>()
            .mockRejectedValueOnce(new Error('broken'))
            .mockResolvedValue(undefined);

        database.open = open;

        const transactionsTable = await ensureTransactionsTable(database);

        expect(transactionsTable).toBe(database.transactions);
        expect(database.close).toHaveBeenCalledTimes(1);
        expect(database.delete).toHaveBeenCalledTimes(1);
        expect(open).toHaveBeenCalledTimes(2);
    });

    it('returns null when the transactions table cannot be restored', async () => {
        const database = createDatabase({
            tables: [],
            transactions: undefined,
            open: vi.fn<() => Promise<unknown>>().mockResolvedValue(undefined),
        });

        const transactionsTable = await ensureTransactionsTable(database);

        expect(transactionsTable).toBeNull();
        expect(database.close).toHaveBeenCalledTimes(1);
        expect(database.delete).toHaveBeenCalledTimes(1);
        expect(database.open).toHaveBeenCalledTimes(2);
    });
});
