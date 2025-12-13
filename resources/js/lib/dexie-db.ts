import type { Transaction } from '@/services/transaction-sync';
import type { Account, AccountBalance, Bank } from '@/types/account';
import type { AutomationRule } from '@/types/automation-rule';
import type { Category } from '@/types/category';
import type { Label } from '@/types/label';
import Dexie, { type EntityTable } from 'dexie';

export interface SyncMetadata {
    key: string;
    value: string;
}

export interface PendingChange {
    id?: number;
    store: string;
    operation: 'create' | 'update' | 'delete';
    data: Record<string, unknown>;
    timestamp: string;
}

const db = new Dexie('whisper_money') as Dexie & {
    transactions: EntityTable<Transaction, 'id'>;
    accounts: EntityTable<Account, 'id'>;
    categories: EntityTable<Category, 'id'>;
    labels: EntityTable<Label, 'id'>;
    banks: EntityTable<Bank, 'id'>;
    automation_rules: EntityTable<AutomationRule, 'id'>;
    account_balances: EntityTable<AccountBalance, 'id'>;
    sync_metadata: EntityTable<SyncMetadata, 'key'>;
    pending_changes: EntityTable<PendingChange, 'id'>;
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

export { db };
