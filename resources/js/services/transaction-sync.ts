import { db, withDb } from '@/lib/dexie-db';
import { TransactionSyncManager } from '@/lib/sync-manager';
import type { LearnedRuleNotice } from '@/types/automation-rule';
import type { Transaction } from '@/types/transaction';
import type { UUID } from '@/types/uuid';
import axios from 'axios';

/** A transaction update plus any rule the correction just taught the system. */
export type UpdatedTransaction = Transaction & {
    learned_rule?: LearnedRuleNotice | null;
};

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
    creditorName?: string;
    debtorName?: string;
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
        options?: { updateBalance?: boolean },
    ): Promise<Transaction> {
        const response = await axios.post('/transactions', {
            ...data,
            ...(options?.updateBalance ? { update_balance: true } : {}),
        });
        const serverData = response.data.data || response.data;

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
    ): Promise<UpdatedTransaction> {
        const { label_ids, ...transactionData } = data;

        const response = await axios.patch(`/transactions/${id}`, {
            ...transactionData,
            label_ids,
        });

        const serverData = response.data.data || response.data;

        const serverLabelIds = serverData.labels?.map(
            (l: { id: string }) => l.id,
        );
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { labels: _labels, ...restServerData } = serverData;

        return {
            ...restServerData,
            transaction_date: String(serverData.transaction_date).slice(0, 10),
            label_ids: serverLabelIds || [],
            learned_rule: response.data.learned_rule ?? null,
        } as UpdatedTransaction;
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
        if (filters.creditorName) {
            requestFilters.creditor_name = filters.creditorName;
        }
        if (filters.debtorName) {
            requestFilters.debtor_name = filters.debtorName;
        }

        const response = await axios.patch('/transactions/bulk', {
            filters: requestFilters,
            label_ids: label_ids,
            ...transactionData,
        });

        return response.data.count || 0;
    }

    async delete(
        id: string,
        options?: { updateBalance?: boolean },
    ): Promise<void> {
        await axios.delete(`/transactions/${id}`, {
            data: options?.updateBalance ? { update_balance: true } : undefined,
        });
        // The API delete above is authoritative; the local cache eviction is
        // best-effort and skipped when IndexedDB is unavailable (PHP-LARAVEL-43).
        await withDb<void>(async () => {
            await db.transactions.delete(id);
        }, undefined);
    }

    async updateManyIndividual(
        updates: Array<{ id: string; data: TransactionUpdateData }>,
    ): Promise<void> {
        for (const { id, data } of updates) {
            await this.update(id, data);
        }
    }

    async deleteMany(
        ids: string[],
        options?: { updateBalance?: boolean },
    ): Promise<void> {
        for (const id of ids) {
            await this.delete(id, options);
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
        if (transactions.length === 0) {
            return [];
        }

        try {
            const response = await axios.post<{ duplicates: boolean[] }>(
                '/api/transactions/check-duplicates',
                {
                    account_id: accountId,
                    transactions: transactions.map((t) => ({
                        transaction_date: t.transaction_date,
                        amount: t.amount,
                        description: t.description,
                    })),
                },
            );

            return response.data.duplicates;
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
