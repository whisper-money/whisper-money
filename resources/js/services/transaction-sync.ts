// Aliased because this service's own methods share these names.
import { index as syncTransactions } from '@/actions/App/Http/Controllers/Sync/TransactionSyncController';
import {
    bulkUpdate as bulkUpdateRoute,
    destroy as destroyRoute,
    store as storeRoute,
    update as updateRoute,
} from '@/actions/App/Http/Controllers/TransactionController';
import {
    store as splitRoute,
    destroy as unsplitRoute,
} from '@/actions/App/Http/Controllers/TransactionSplitController';
import { db, withDb } from '@/lib/dexie-db';
import { TransactionSyncManager } from '@/lib/sync-manager';
import { serializeFilters } from '@/lib/transaction-filter-serialization';
import type { SplitInput } from '@/lib/transaction-splits';
import type { LearnedRuleNotice } from '@/types/automation-rule';
import type {
    ServerTransaction,
    Transaction,
    TransactionFilters,
} from '@/types/transaction';
import type { UUID } from '@/types/uuid';
import axios from 'axios';

/** A transaction update plus any rule the correction just taught the system. */
export type UpdatedTransaction = ServerTransaction & {
    learned_rule?: LearnedRuleNotice | null;
};

/**
 * A split is made and undone through split() and unsplit(), never through an
 * update, so the lines of the old model are not part of it.
 */
export interface TransactionUpdateData extends Omit<
    Partial<Transaction>,
    'splits' | 'split_parent_id' | 'split_siblings'
> {
    label_ids?: string[];
}

/**
 * `label_ids` adds to the labels each row has. Taking them all off is asked for
 * by name, so an edit that lost its labels on the way can never clear a row.
 */
export type BulkTransactionUpdateData = TransactionUpdateData & {
    remove_all_labels?: true;
};

export type TransactionCreateData = Omit<
    Transaction,
    | 'id'
    | 'created_at'
    | 'updated_at'
    | 'splits'
    | 'split_parent_id'
    | 'split_siblings'
>;

export interface BulkUpdateResult {
    updated_count: number;
    skipped_split_count: number;
    updated_ids: string[];
    skipped_split_ids: string[];
    transactions: ServerTransaction[];
}

export function transformTransactionFromServer(
    data: Record<string, unknown>,
): Record<string, unknown> {
    const serverLabels = data.labels as unknown;
    const label_ids = Array.isArray(serverLabels)
        ? serverLabels.map((label) => String((label as { id: unknown }).id))
        : [];
    // Relations other than labels (including embedded splits) remain intact.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { labels, ...rest } = data;

    return {
        ...rest,
        transaction_date: String(data.transaction_date).slice(0, 10),
        label_ids,
    };
}

class TransactionSyncService {
    private syncManager: TransactionSyncManager;

    constructor() {
        this.syncManager = new TransactionSyncManager({
            endpoint: syncTransactions.url(),
            transformFromServer: transformTransactionFromServer,
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
        data: TransactionCreateData,
        options?: { updateBalance?: boolean },
    ): Promise<Transaction> {
        const response = await axios.post(storeRoute.url(), {
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
        transactions: TransactionCreateData[],
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
        options?: { updateBalance?: boolean },
    ): Promise<UpdatedTransaction> {
        const { label_ids, ...transactionData } = data;

        const response = await axios.patch(updateRoute.url(id), {
            ...transactionData,
            label_ids,
            ...(options?.updateBalance ? { update_balance: true } : {}),
        });

        const serverData = response.data.data || response.data;

        const serverLabelIds = serverData.labels?.map(
            (l: { id: string }) => l.id,
        );
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { labels: _labels, ...restServerData } = serverData;

        const updatedTransaction = {
            ...restServerData,
            transaction_date: String(serverData.transaction_date).slice(0, 10),
            label_ids: serverLabelIds || [],
        } as Transaction;

        await withDb<void>(async () => {
            await db.transactions.put(updatedTransaction);
        }, undefined);

        return {
            ...updatedTransaction,
            labels: Array.isArray(serverData.labels) ? serverData.labels : [],
            learned_rule: response.data.learned_rule ?? null,
        } as UpdatedTransaction;
    }

    async updateMany(
        ids: string[],
        data: BulkTransactionUpdateData,
    ): Promise<BulkUpdateResult> {
        const { label_ids, ...transactionData } = data;

        const response = await axios.patch(bulkUpdateRoute.url(), {
            transaction_ids: ids,
            label_ids: label_ids,
            ...transactionData,
        });

        return await this.persistBulkResult(response.data);
    }

    async updateByFilters(
        filters: TransactionFilters,
        data: BulkTransactionUpdateData,
    ): Promise<BulkUpdateResult> {
        const { label_ids, ...transactionData } = data;

        // The serializer saved filters and the analysis drawer use, so a
        // select-all edit targets the rows the list shows: the cashflow block
        // included, sent only when both dates are set.
        const requestFilters = {
            ...serializeFilters(filters),
            ...(filters.aiCategorizedOnly ? { category_source: 'ai' } : {}),
        };

        const response = await axios.patch(bulkUpdateRoute.url(), {
            filters: requestFilters,
            label_ids: label_ids,
            ...transactionData,
        });

        return await this.persistBulkResult(response.data);
    }

    private async persistBulkResult(
        data: Record<string, unknown>,
    ): Promise<BulkUpdateResult> {
        const sourceTransactions = Array.isArray(data.transactions)
            ? (data.transactions as Record<string, unknown>[])
            : [];
        const storedTransactions = sourceTransactions.map((transaction) =>
            transformTransactionFromServer(transaction),
        );
        const transactions = storedTransactions.map((transaction, index) => ({
            ...transaction,
            labels: Array.isArray(sourceTransactions[index].labels)
                ? (sourceTransactions[index]
                      .labels as ServerTransaction['labels'])
                : [],
        })) as unknown as ServerTransaction[];

        await withDb<void>(async () => {
            for (const transaction of storedTransactions) {
                await db.transactions.put(
                    transaction as unknown as Transaction,
                );
            }
        }, undefined);

        return {
            ...(data as unknown as BulkUpdateResult),
            updated_ids: Array.isArray(data.updated_ids)
                ? data.updated_ids.map(String)
                : [],
            skipped_split_ids: Array.isArray(data.skipped_split_ids)
                ? data.skipped_split_ids.map(String)
                : [],
            transactions,
        };
    }

    async delete(
        id: string,
        options?: { updateBalance?: boolean },
    ): Promise<void> {
        await axios.delete(destroyRoute.url(id), {
            data: options?.updateBalance ? { update_balance: true } : undefined,
        });
        // The API delete above is authoritative; the local cache eviction is
        // best-effort and skipped when IndexedDB is unavailable (PHP-LARAVEL-43).
        await withDb<void>(async () => {
            await db.transactions.delete(id);
        }, undefined);
    }

    /**
     * Split a transaction into parts. The original is soft-deleted server-side
     * and the sync endpoint never announces deletions, so it is evicted from the
     * offline cache here (best-effort, like delete()).
     */
    async split(id: string, splits: SplitInput[]): Promise<Transaction[]> {
        const response = await axios.post(splitRoute.url(id), { splits });

        await withDb<void>(async () => {
            await db.transactions.delete(id);
        }, undefined);

        const parts = (response.data.data ?? []) as Record<string, unknown>[];

        return parts.map(
            (part) =>
                transformTransactionFromServer(part) as unknown as Transaction,
        );
    }

    /**
     * Merge a split back: every part goes away and the original comes back.
     * Takes any of the parts, and evicts all of them from the offline cache the
     * way split() evicts the original (best-effort, like delete()).
     */
    async unsplit(id: string, partIds: string[] = []): Promise<Transaction> {
        const response = await axios.delete(unsplitRoute.url(id));

        await withDb<void>(async () => {
            await db.transactions.bulkDelete([...new Set([id, ...partIds])]);
        }, undefined);

        return transformTransactionFromServer(
            response.data.data,
        ) as unknown as Transaction;
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
