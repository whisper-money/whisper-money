import { encrypt, importKey } from '@/lib/crypto';
import { indexedDBService } from '@/lib/indexeddb';
import { getStoredKey } from '@/lib/key-storage';
import { SyncManager } from '@/lib/sync-manager';
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
            return allTransactions.filter((t) => t.account_id === accountId);
        } catch (error) {
            console.warn('Failed to get transactions from IndexedDB:', error);
            return [];
        }
    }

    async create(
        data: Omit<Transaction, 'id' | 'created_at' | 'updated_at'>,
    ): Promise<Transaction> {
        return await this.syncManager.createLocal<Transaction>(
            data as Omit<Transaction, 'id' | 'created_at' | 'updated_at'> & {
                id?: number;
                created_at?: string;
                updated_at?: string;
            },
        );
    }

    async createMany(
        transactions: Omit<Transaction, 'id' | 'created_at' | 'updated_at'>[],
    ): Promise<Transaction[]> {
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
            throw new Error(
                'Failed to save transactions locally. Please refresh the page and try again.',
            );
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
        await indexedDBService.addPendingChange(
            'transactions',
            'update',
            updated,
        );
    }

    async updateMany(ids: string[], data: Partial<Transaction>): Promise<void> {
        const timestamp = new Date().toISOString();

        for (const id of ids) {
            const existing = await this.getById(id);
            if (!existing) {
                console.warn(`Transaction ${id} not found, skipping`);
                continue;
            }

            const updated = {
                ...existing,
                ...data,
                updated_at: timestamp,
            };

            await indexedDBService.put('transactions', updated);
            await indexedDBService.addPendingChange(
                'transactions',
                'update',
                updated,
            );
        }
    }

    async delete(id: string): Promise<void> {
        const transaction = await this.getById(id);
        if (!transaction) {
            throw new Error('Transaction not found');
        }

        await indexedDBService.delete('transactions', id);
        await indexedDBService.addPendingChange(
            'transactions',
            'delete',
            transaction,
        );
    }

    async deleteMany(ids: string[]): Promise<void> {
        for (const id of ids) {
            const transaction = await this.getById(id);
            if (!transaction) {
                console.warn(`Transaction ${id} not found, skipping`);
                continue;
            }

            await indexedDBService.delete('transactions', id);
            await indexedDBService.addPendingChange(
                'transactions',
                'delete',
                transaction,
            );
        }
    }

    async checkDuplicates(
        accountId: number,
        transactions: Array<{
            transaction_date: string;
            amount: number;
            description: string;
        }>,
    ): Promise<boolean[]> {
        try {
            if (transactions.length === 0) {
                return [];
            }

            const dates = transactions.map((t) => t.transaction_date);
            const minDate = dates.reduce((a, b) => (a < b ? a : b));
            const maxDate = dates.reduce((a, b) => (a > b ? a : b));

            const allTransactions = await this.getByAccountId(accountId);
            const transactionsInRange = allTransactions.filter(
                (t) =>
                    t.transaction_date >= minDate &&
                    t.transaction_date <= maxDate,
            );

            console.log(
                `Checking duplicates for ${transactions.length} transactions. Found ${transactionsInRange.length} existing transactions between ${minDate} and ${maxDate}`,
            );

            const keyString = getStoredKey();
            if (!keyString) {
                console.warn('No encryption key found for duplicate check');
                return transactions.map(() => false);
            }

            const key = await importKey(keyString);
            const { decrypt } = await import('@/lib/crypto');

            const decryptedTransactions = await Promise.all(
                transactionsInRange.map(async (t) => {
                    try {
                        const decryptedDescription = await decrypt(
                            t.description,
                            key,
                            t.description_iv,
                        );
                        return {
                            transaction_date: t.transaction_date,
                            amount: parseFloat(t.amount),
                            description: decryptedDescription
                                .toLowerCase()
                                .trim()
                                .replace(/\s+/g, ' '),
                        };
                    } catch (error) {
                        console.error(
                            'Failed to decrypt transaction:',
                            t.id,
                            error,
                        );
                        return null;
                    }
                }),
            );

            const validDecryptedTransactions = decryptedTransactions.filter(
                (t) => t !== null,
            );

            console.log(
                `Successfully decrypted ${validDecryptedTransactions.length} transactions`,
            );

            const results = transactions.map((importingTx) => {
                const normalizedDescription = importingTx.description
                    .toLowerCase()
                    .trim()
                    .replace(/\s+/g, ' ');

                const isDuplicate = validDecryptedTransactions.some(
                    (existing) =>
                        existing.transaction_date ===
                            importingTx.transaction_date &&
                        Math.abs(existing.amount - importingTx.amount) <
                            0.001 &&
                        existing.description === normalizedDescription,
                );

                return isDuplicate;
            });

            const duplicateCount = results.filter((r) => r).length;
            console.log(
                `Found ${duplicateCount} duplicates out of ${transactions.length} transactions`,
            );

            return results;
        } catch (error) {
            console.warn(
                'Duplicate check failed, assuming no duplicates:',
                error,
            );
            return transactions.map(() => false);
        }
    }

    async isDuplicate(
        accountId: number,
        transactionDate: string,
        amount: number,
        description: string,
    ): Promise<boolean> {
        const results = await this.checkDuplicates(accountId, [
            { transaction_date: transactionDate, amount, description },
        ]);
        return results[0] || false;
    }

    async encryptDescription(
        description: string,
    ): Promise<{ encrypted: string; iv: string }> {
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
