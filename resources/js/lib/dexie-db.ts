import type { Budget, BudgetPeriod } from '@/types/budget';
import type { Transaction } from '@/types/transaction';
import type { UUID } from '@/types/uuid';
import Dexie, { type EntityTable } from 'dexie';

export interface SyncMetadata {
    key: string;
    value: string;
}

type WhisperMoneyDB = Dexie & {
    transactions: EntityTable<Transaction, 'id'>;
    budgets: EntityTable<Budget, 'id'>;
    budget_categories: EntityTable<{ id: UUID }, 'id'>;
    budget_periods: EntityTable<BudgetPeriod, 'id'>;
    budget_period_allocations: EntityTable<{ id: UUID }, 'id'>;
    sync_metadata: EntityTable<SyncMetadata, 'key'>;
};

export interface TransactionStoreLike {
    toArray(): Promise<Transaction[]>;
    get(key: UUID): Promise<Transaction | undefined>;
    where(index: string): {
        equals(key: UUID): {
            toArray(): Promise<Transaction[]>;
        };
    };
    bulkPut(records: readonly Transaction[]): Promise<unknown>;
    clear(): Promise<void>;
    delete(key: UUID): Promise<void>;
}

export interface TransactionDatabaseLike {
    open(): Promise<unknown>;
    close(): void;
    delete(): Promise<void>;
    tables: Array<{ name: string }>;
    transactions?: TransactionStoreLike;
}

let dbInstance: WhisperMoneyDB | null = null;

function initializeDatabase(): WhisperMoneyDB {
    const database = new Dexie('whisper_money') as WhisperMoneyDB;

    database.version(5).stores({
        transactions: 'id, user_id, account_id, updated_at',
        accounts: 'id, user_id, bank_id, updated_at',
        categories: 'id, user_id, updated_at',
        banks: 'id, user_id, updated_at',
        automation_rules: 'id, user_id, priority, updated_at',
        account_balances: 'id, account_id, balance_date, updated_at',
        sync_metadata: 'key',
        pending_changes: '++id, store, timestamp',
    });

    database.version(6).stores({
        transactions: 'id, user_id, account_id, updated_at',
        accounts: 'id, user_id, bank_id, updated_at',
        categories: 'id, user_id, updated_at',
        labels: 'id, user_id, updated_at',
        banks: 'id, user_id, updated_at',
        automation_rules: 'id, user_id, priority, updated_at',
        account_balances: 'id, account_id, balance_date, updated_at',
        sync_metadata: 'key',
        pending_changes: '++id, store, timestamp',
    });

    // Version 7: Remove all tables except transactions and sync_metadata
    database.version(7).stores({
        transactions: 'id, user_id, account_id, updated_at',
        sync_metadata: 'key',
        // Delete removed tables
        accounts: null,
        categories: null,
        labels: null,
        banks: null,
        automation_rules: null,
        account_balances: null,
        pending_changes: null,
    });

    // Version 8: Ensure clean state (no schema changes, just trigger upgrade)
    database.version(8).stores({
        transactions: 'id, user_id, account_id, updated_at',
        sync_metadata: 'key',
    });

    // Version 9: Add budget tables
    database.version(9).stores({
        transactions: 'id, user_id, account_id, updated_at',
        budgets: 'id, user_id, updated_at',
        budget_categories: 'id, budget_id, updated_at',
        budget_periods: 'id, budget_id, start_date, updated_at',
        budget_period_allocations:
            'id, budget_period_id, budget_category_id, updated_at',
        sync_metadata: 'key',
    });

    return database;
}

function hasTransactionsTable(database: TransactionDatabaseLike): boolean {
    return database.tables.some((table) => table.name === 'transactions');
}

async function resetTransactionDatabase(
    database: TransactionDatabaseLike,
): Promise<void> {
    database.close();
    await database.delete();
    await database.open();
}

export async function ensureTransactionsTable(
    database: TransactionDatabaseLike = db,
): Promise<TransactionStoreLike | null> {
    try {
        await database.open();
    } catch (error) {
        console.warn(
            'Failed to open IndexedDB transaction cache. Resetting local cache.',
            error,
        );

        try {
            await resetTransactionDatabase(database);
        } catch (resetError) {
            console.error(
                'Failed to reset IndexedDB transaction cache.',
                resetError,
            );

            return null;
        }
    }

    if (database.transactions && hasTransactionsTable(database)) {
        return database.transactions;
    }

    console.warn(
        'IndexedDB transaction cache is missing the transactions table. Resetting local cache.',
    );

    try {
        await resetTransactionDatabase(database);
    } catch (resetError) {
        console.error(
            'Failed to rebuild IndexedDB transaction cache.',
            resetError,
        );

        return null;
    }

    if (database.transactions && hasTransactionsTable(database)) {
        return database.transactions;
    }

    return null;
}

export const db = new Proxy({} as WhisperMoneyDB, {
    get(_target, prop) {
        if (!dbInstance) {
            dbInstance = initializeDatabase();
        }
        return dbInstance[prop as keyof WhisperMoneyDB];
    },
});
