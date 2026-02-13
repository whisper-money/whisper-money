import { importKey } from '@/lib/crypto';
import { db } from '@/lib/dexie-db';
import { getStoredKey } from '@/lib/key-storage';
import { TransactionSyncManager } from '@/lib/sync-manager';
import type { Transaction } from '@/types/transaction';
import type { UUID } from '@/types/uuid';
import axios from 'axios';

interface TransactionUpdateData extends Partial<Transaction> {
    label_ids?: string[];
}

interface TransactionFilters {
    dateFrom?: Date | null;
    dateTo?: Date | null;
    amountMin?: number | null;
    amountMax?: number | null;
    categoryIds?: number[];
    accountIds?: string[];
    labelIds?: string[];
    searchText?: string;
}

class TransactionSyncService {
    private syncManager: TransactionSyncManager;

    constructor() {
        this.syncManager = new TransactionSyncManager({
            endpoint: '/api/sync/transactions',
            transformFromServer: (data) => {
                const label_ids = data.labels?.map((l: { id: string }) => l.id);
                // eslint-disable-next-line @typescript-eslint/no-unused-vars
                const { labels, ...rest } = data;
                return {
                    ...rest,
                    transaction_date: String(data.transaction_date).slice(
                        0,
                        10,
                    ),
                    label_ids: label_ids || [],
                };
            },
        });
    }

    async sync() {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<Transaction[]> {
        return await this.syncManager.getAll();
    }

    async getById(id: UUID): Promise<Transaction | null> {
        return await this.syncManager.getById(id);
    }

    async getByAccountId(accountId: UUID): Promise<Transaction[]> {
        return await this.syncManager.getByAccountId(accountId);
    }

    async create(
        data: Omit<Transaction, 'id' | 'created_at' | 'updated_at'>,
    ): Promise<Transaction> {
        const response = await axios.post('/transactions', data);
        const serverData = response.data.data;

        const label_ids = serverData.labels?.map((l: { id: string }) => l.id);
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { labels, ...rest } = serverData;

        return {
            ...rest,
            transaction_date: String(serverData.transaction_date).slice(0, 10),
            label_ids: label_ids || [],
        } as Transaction;
    }

    async createMany(
        transactions: Omit<Transaction, 'id' | 'created_at' | 'updated_at'>[],
    ): Promise<Transaction[]> {
        const created: Transaction[] = [];

        for (const data of transactions) {
            const transaction = await this.create(data);
            created.push(transaction);
        }

        return created;
    }

    async update(
        id: string,
        data: TransactionUpdateData,
    ): Promise<Transaction> {
        const { label_ids, ...transactionData } = data;

        const response = await axios.patch(`/transactions/${id}`, {
            ...transactionData,
            label_ids,
        });

        const serverData = response.data.data;

        const serverLabelIds = serverData.labels?.map(
            (l: { id: string }) => l.id,
        );
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { labels: _labels, ...restServerData } = serverData;

        return {
            ...restServerData,
            transaction_date: String(serverData.transaction_date).slice(0, 10),
            label_ids: serverLabelIds || [],
        } as Transaction;
    }

    async updateMany(
        ids: string[],
        data: TransactionUpdateData,
    ): Promise<void> {
        const { label_ids, ...transactionData } = data;

        await axios.patch('/transactions/bulk', {
            transaction_ids: ids,
            label_ids: label_ids,
            ...transactionData,
        });
    }

    async updateByFilters(
        filters: TransactionFilters,
        data: TransactionUpdateData,
    ): Promise<number> {
        const { label_ids, ...transactionData } = data;

        const requestFilters: Record<string, unknown> = {};
        if (filters.dateFrom) {
            requestFilters.date_from = filters.dateFrom
                .toISOString()
                .split('T')[0];
        }
        if (filters.dateTo) {
            requestFilters.date_to = filters.dateTo.toISOString().split('T')[0];
        }
        if (filters.amountMin !== null && filters.amountMin !== undefined) {
            requestFilters.amount_min = filters.amountMin;
        }
        if (filters.amountMax !== null && filters.amountMax !== undefined) {
            requestFilters.amount_max = filters.amountMax;
        }
        if (filters.categoryIds && filters.categoryIds.length > 0) {
            requestFilters.category_ids = filters.categoryIds;
        }
        if (filters.accountIds && filters.accountIds.length > 0) {
            requestFilters.account_ids = filters.accountIds;
        }
        if (filters.labelIds && filters.labelIds.length > 0) {
            requestFilters.label_ids = filters.labelIds;
        }

        const response = await axios.patch('/transactions/bulk', {
            filters: requestFilters,
            label_ids: label_ids,
            ...transactionData,
        });

        return response.data.count || 0;
    }

    async delete(id: string): Promise<void> {
        await axios.delete(`/transactions/${id}`);
        await db.transactions.delete(id);
    }

    async updateManyIndividual(
        updates: Array<{ id: string; data: TransactionUpdateData }>,
    ): Promise<void> {
        for (const { id, data } of updates) {
            await this.update(id, data);
        }
    }

    async deleteMany(ids: string[]): Promise<void> {
        for (const id of ids) {
            await this.delete(id);
        }
    }

    async checkDuplicates(
        accountId: string,
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

            const normalizeDate = (dateStr: string): string =>
                dateStr.slice(0, 10);

            const allTransactions = await this.getByAccountId(accountId);
            const transactionsInRange = allTransactions.filter((t) => {
                const txDate = normalizeDate(t.transaction_date);
                return txDate >= minDate && txDate <= maxDate;
            });

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
                        const decryptedDescription = t.description_iv
                            ? await decrypt(
                                  t.description,
                                  key,
                                  t.description_iv,
                              )
                            : t.description;
                        return {
                            transaction_date: normalizeDate(t.transaction_date),
                            amount: parseFloat(t.amount),
                            description: decryptedDescription
                                .toLowerCase()
                                .trim()
                                .replace(/\s+/g, ' '),
                        };
                    } catch {
                        return null;
                    }
                }),
            );

            const validDecryptedTransactions = decryptedTransactions.filter(
                (t) => t !== null,
            );

            return transactions.map((importingTx) => {
                const normalizedDescription = importingTx.description
                    .toLowerCase()
                    .trim()
                    .replace(/\s+/g, ' ');

                return validDecryptedTransactions.some(
                    (existing) =>
                        existing.transaction_date ===
                            importingTx.transaction_date &&
                        Math.abs(existing.amount - importingTx.amount) <
                            0.001 &&
                        existing.description === normalizedDescription,
                );
            });
        } catch (error) {
            console.warn(
                'Duplicate check failed, assuming no duplicates:',
                error,
            );
            return transactions.map(() => false);
        }
    }

    async getLastSyncTime(): Promise<string | null> {
        return await this.syncManager.getLastSyncTime();
    }

    isSyncing(): boolean {
        return this.syncManager.isSyncing();
    }

    async clearAll(): Promise<void> {
        await this.syncManager.clearAll();
    }
}

export const transactionSyncService = new TransactionSyncService();
