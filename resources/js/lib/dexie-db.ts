import type {
    Budget,
    BudgetCategory,
    BudgetLabel,
    BudgetPeriod,
} from '@/types/budget';
import type { Transaction } from '@/types/transaction';
import Dexie, { type EntityTable } from 'dexie';

export interface SyncMetadata {
    key: string;
    value: string;
}

type WhisperMoneyDB = Dexie & {
    transactions: EntityTable<Transaction, 'id'>;
    budgets: EntityTable<Budget, 'id'>;
    budget_categories: EntityTable<BudgetCategory, 'id'>;
    budget_labels: EntityTable<BudgetLabel, 'id'>;
    budget_periods: EntityTable<BudgetPeriod, 'id'>;
    sync_metadata: EntityTable<SyncMetadata, 'key'>;
};

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

    // Version 10: Multi-category/label budgets share a single pool, so the
    // per-category allocations table is dropped and a budget_labels pivot added.
    database.version(10).stores({
        transactions: 'id, user_id, account_id, updated_at',
        budgets: 'id, user_id, updated_at',
        budget_categories: 'id, budget_id, updated_at',
        budget_labels: 'id, budget_id, updated_at',
        budget_periods: 'id, budget_id, start_date, updated_at',
        budget_period_allocations: null,
        sync_metadata: 'key',
    });

    return database;
}

export const db = new Proxy({} as WhisperMoneyDB, {
    get(_target, prop) {
        if (!dbInstance) {
            dbInstance = initializeDatabase();
        }
        return dbInstance[prop as keyof WhisperMoneyDB];
    },
});

/**
 * Whether IndexedDB is usable in the current context. Some browsers do not
 * expose the `indexedDB` global at all — Chrome on iOS in certain webviews,
 * private/locked-down modes — so any Dexie operation throws
 * "ReferenceError: Can't find variable: indexedDB" (see PHP-LARAVEL-43). Checked
 * via `globalThis` so referencing the global never throws, and wrapped
 * defensively against exotic throwing getters.
 */
export function isIndexedDbAvailable(): boolean {
    try {
        return (
            typeof globalThis.indexedDB !== 'undefined' &&
            globalThis.indexedDB !== null
        );
    } catch {
        return false;
    }
}

/**
 * Run a Dexie operation, degrading to `fallback` when IndexedDB is unavailable
 * or the operation fails. IndexedDB is only an offline cache here (the API is
 * the source of truth), so a missing or broken store must never crash the app —
 * it just means no local cache. Pass ONLY Dexie work in `operation`; keep API
 * calls outside so they always run. The closure defers `db` access (which lazily
 * opens Dexie) until after the availability check passes.
 */
export async function withDb<T>(
    operation: () => Promise<T>,
    fallback: T,
): Promise<T> {
    if (!isIndexedDbAvailable()) {
        return fallback;
    }

    try {
        return await operation();
    } catch (error) {
        console.debug('IndexedDB operation failed; using fallback', error);

        return fallback;
    }
}
