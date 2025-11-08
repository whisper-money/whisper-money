import { SyncManager } from '@/lib/sync-manager';
import { indexedDBService } from '@/lib/indexeddb';
import { encrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { uuidv7 } from 'uuidv7';

export interface Transaction {
    id: string;
    user_id: number;
    account_id: number;
    category_id: number | null;
    description: string;
    description_iv: string;
    transaction_date: string;
    amount: string;
    currency_code: string;
    notes: string | null;
    notes_iv: string | null;
    created_at: string;
    updated_at: string;
}

class TransactionSyncService {
    private syncManager: SyncManager;

    constructor() {
        this.syncManager = new SyncManager({
            storeName: 'transactions',
            endpoint: '/api/sync/transactions',
        });
    }

    async sync() {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<Transaction[]> {
        return await this.syncManager.getAll<Transaction>();
    }

    async getById(id: string): Promise<Transaction | null> {
        return await indexedDBService.get<Transaction>('transactions', id);
    }

    async getByAccountId(accountId: number): Promise<Transaction[]> {
        try {
            const allTransactions = await this.getAll();
            return allTransactions.filter(t => t.account_id === accountId);
        } catch (error) {
            console.warn('Failed to get transactions from IndexedDB:', error);
            return [];
        }
    }

    async create(data: Omit<Transaction, 'id' | 'created_at' | 'updated_at'>): Promise<Transaction> {
        return await this.syncManager.createLocal<Transaction>(data as Omit<Transaction, 'id' | 'created_at' | 'updated_at'> & { id?: number; created_at?: string; updated_at?: string });
    }

    async createMany(transactions: Omit<Transaction, 'id' | 'created_at' | 'updated_at'>[]): Promise<Transaction[]> {
        try {
            const timestamp = new Date().toISOString();
            const created: Transaction[] = [];

            for (const data of transactions) {
                const record = {
                    ...data,
                    id: uuidv7(),
                    created_at: timestamp,
                    updated_at: timestamp,
                } as Transaction;

                await indexedDBService.put('transactions', record);
                await indexedDBService.addPendingChange(
                    'transactions',
                    'create',
                    record,
                );

                created.push(record);
            }

            return created;
        } catch (error) {
            console.error('Failed to create transactions in IndexedDB:', error);
            throw new Error('Failed to save transactions locally. Please refresh the page and try again.');
        }
    }

    async update(id: string, data: Partial<Transaction>): Promise<void> {
        const existing = await this.getById(id);
        if (!existing) {
            throw new Error('Transaction not found');
        }

        const updated = {
            ...existing,
            ...data,
            updated_at: new Date().toISOString(),
        };

        await indexedDBService.put('transactions', updated);
        await indexedDBService.addPendingChange('transactions', 'update', updated);
    }

    async delete(id: string): Promise<void> {
        const transaction = await this.getById(id);
        if (!transaction) {
            throw new Error('Transaction not found');
        }

        await indexedDBService.delete('transactions', id);
        await indexedDBService.addPendingChange('transactions', 'delete', transaction);
    }

    async isDuplicate(
        accountId: number,
        transactionDate: string,
        amount: number,
        description: string
    ): Promise<boolean> {
        try {
            const existingTransactions = await this.getByAccountId(accountId);
            
            const keyString = getStoredKey();
            if (!keyString) {
                return false;
            }

            const key = await importKey(keyString);

            for (const existing of existingTransactions) {
                if (
                    existing.transaction_date === transactionDate &&
                    parseFloat(existing.amount) === amount
                ) {
                    try {
                        const { decrypt } = await import('@/lib/crypto');
                        const decryptedExisting = await decrypt(
                            existing.description,
                            key,
                            existing.description_iv
                        );

                        if (decryptedExisting.toLowerCase() === description.toLowerCase()) {
                            return true;
                        }
                    } catch (error) {
                        console.error('Failed to decrypt description for duplicate check:', error);
                    }
                }
            }

            return false;
        } catch (error) {
            console.warn('Duplicate check failed, assuming no duplicates:', error);
            return false;
        }
    }

    async encryptDescription(description: string): Promise<{ encrypted: string; iv: string }> {
        const keyString = getStoredKey();
        if (!keyString) {
            throw new Error('Encryption key not set');
        }

        const key = await importKey(keyString);
        return await encrypt(description, key);
    }

    async getLastSyncTime(): Promise<string | null> {
        return await this.syncManager.getLastSyncTime();
    }

    isSyncing(): boolean {
        return this.syncManager.isSyncing();
    }
}

export const transactionSyncService = new TransactionSyncService();

