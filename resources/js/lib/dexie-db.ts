import type { Transaction } from '@/types/transaction';
import Dexie, { type EntityTable } from 'dexie';

export interface SyncMetadata {
    key: string;
    value: string;
}

const db = new Dexie('whisper_money') as Dexie & {
    transactions: EntityTable<Transaction, 'id'>;
    sync_metadata: EntityTable<SyncMetadata, 'key'>;
};

db.version(5).stores({
    transactions: 'id, user_id, account_id, updated_at',
    accounts: 'id, user_id, bank_id, updated_at',
    categories: 'id, user_id, updated_at',
    banks: 'id, user_id, updated_at',
    automation_rules: 'id, user_id, priority, updated_at',
    account_balances: 'id, account_id, balance_date, updated_at',
    sync_metadata: 'key',
    pending_changes: '++id, store, timestamp',
});

db.version(6).stores({
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
db.version(7).stores({
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

export { db };
