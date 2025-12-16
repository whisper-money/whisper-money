import { encrypt, importKey } from '@/lib/crypto';
import { db } from '@/lib/dexie-db';
import { getStoredKey } from '@/lib/key-storage';
import { SyncManager } from '@/lib/sync-manager';
import type { UUID } from '@/types/uuid';
import { uuidv7 } from 'uuidv7';

export interface Transaction {
    id: UUID;
    user_id: UUID;
    account_id: UUID;
    category_id: UUID | null;
    description: string;
    description_iv: string;
    transaction_date: string;
    amount: string;
    currency_code: string;
    notes: string | null;
    notes_iv: string | null;
    label_ids?: UUID[];
    created_at: string;
    updated_at: string;
}

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
    private syncManager: SyncManager;

    constructor() {
        this.syncManager = new SyncManager({
            storeName: 'transactions',
            endpoint: '/api/sync/transactions',
            transformFromServer: (data) => {
                // Extract label_ids from labels array if present
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
        return await this.syncManager.getAll<Transaction>();
    }

    async getById(id: UUID): Promise<Transaction | null> {
        return (await db.transactions.get(id)) || null;
    }

    async getByAccountId(accountId: UUID): Promise<Transaction[]> {
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

                await db.transactions.put(record);
                await db.pending_changes.add({
                    store: 'transactions',
                    operation: 'create',
                    data: record,
                    timestamp,
                });

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

    async update(
        id: string,
        data: TransactionUpdateData,
    ): Promise<Transaction | void> {
        const existing = await this.getById(id);

        if (!existing) {
            throw new Error('Transaction not found');
        }

        const { label_ids, ...transactionData } = data;
        const timestamp = new Date().toISOString();

        // If label_ids are provided, we need to sync with the server immediately
        if (label_ids !== undefined) {
            const csrfToken = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((row) => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] || '',
            );

            const response = await fetch(`/api/sync/transactions/${id}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    ...existing,
                    ...transactionData,
                    label_ids,
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to update transaction');
            }

            const result = await response.json();
            const serverData = result.data;

            // Extract label_ids from labels array
            const serverLabelIds = serverData.labels?.map(
                (l: { id: string }) => l.id,
            );
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
            const { labels: _labels, ...restServerData } = serverData;

            const updatedTransaction: Transaction = {
                ...restServerData,
                transaction_date: String(serverData.transaction_date).slice(
                    0,
                    10,
                ),
                label_ids: serverLabelIds || [],
            };

            // Update local storage with transformed data (label_ids instead of labels)
            await db.transactions.put(updatedTransaction);

            return updatedTransaction;
        }

        // No label_ids, use the normal offline-first approach
        const updated = {
            ...existing,
            ...transactionData,
            updated_at: timestamp,
        };

        await db.transactions.put(updated);
        await db.pending_changes.add({
            store: 'transactions',
            operation: 'update',
            data: updated,
            timestamp,
        });
    }

    async updateMany(
        ids: string[],
        data: TransactionUpdateData,
    ): Promise<void> {
        const timestamp = new Date().toISOString();
        const { label_ids, ...transactionData } = data;

        if (label_ids !== undefined) {
            try {
                const csrfToken = decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((row) => row.startsWith('XSRF-TOKEN='))
                        ?.split('=')[1] || '',
                );

                const response = await fetch('/transactions/bulk', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        transaction_ids: ids,
                        label_ids: label_ids,
                        ...transactionData,
                    }),
                });

                if (!response.ok) {
                    throw new Error('Failed to bulk update transactions');
                }

                // Update IndexedDB with the label_ids after successful API call
                // Use bulkPut to update all transactions at once (single operation)
                const updates = [];
                for (const id of ids) {
                    const existing = await this.getById(id);
                    if (existing) {
                        // Merge labels: if label_ids is empty, clear all; otherwise merge with existing
                        const mergedLabelIds =
                            label_ids.length === 0
                                ? []
                                : Array.from(
                                      new Set([
                                          ...(existing.label_ids || []),
                                          ...label_ids,
                                      ]),
                                  );

                        updates.push({
                            ...existing,
                            ...transactionData,
                            label_ids: mergedLabelIds,
                            updated_at: timestamp,
                        });
                    }
                }

                if (updates.length > 0) {
                    await db.transactions.bulkPut(updates);
                }

                return;
            } catch (error) {
                console.error('Failed to update transactions via API:', error);
                throw error;
            }
        }

        // Batch update for non-label updates (e.g., category changes)
        const updates = [];
        const pendingChanges = [];

        for (const id of ids) {
            const existing = await this.getById(id);

            if (!existing) {
                console.warn(`Transaction ${id} not found, skipping`);
                continue;
            }

            const updated = {
                ...existing,
                ...transactionData,
                updated_at: timestamp,
            };

            updates.push(updated);
            pendingChanges.push({
                store: 'transactions',
                operation: 'update',
                data: updated,
                timestamp,
            });
        }

        if (updates.length > 0) {
            await db.transactions.bulkPut(updates);
            await db.pending_changes.bulkAdd(pendingChanges);
        }
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

        try {
            const csrfToken = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((row) => row.startsWith('XSRF-TOKEN='))
                    ?.split('=')[1] || '',
            );

            const response = await fetch('/transactions/bulk', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    filters: requestFilters,
                    label_ids: label_ids,
                    ...transactionData,
                }),
            });

            if (!response.ok) {
                throw new Error(
                    'Failed to bulk update transactions by filters',
                );
            }

            const result = await response.json();

            // Update IndexedDB locally instead of doing a full sync
            // Get all transactions that match the filters and update them
            const allTransactions = await db.transactions.toArray();
            const updates = [];
            const timestamp = new Date().toISOString();

            for (const transaction of allTransactions) {
                // Apply the same filters as the backend
                let matches = true;

                if (
                    filters.dateFrom &&
                    transaction.transaction_date <
                        filters.dateFrom.toISOString().split('T')[0]
                ) {
                    matches = false;
                }
                if (
                    filters.dateTo &&
                    transaction.transaction_date >
                        filters.dateTo.toISOString().split('T')[0]
                ) {
                    matches = false;
                }
                if (
                    filters.amountMin !== null &&
                    filters.amountMin !== undefined &&
                    parseFloat(transaction.amount) < filters.amountMin * 100
                ) {
                    matches = false;
                }
                if (
                    filters.amountMax !== null &&
                    filters.amountMax !== undefined &&
                    parseFloat(transaction.amount) > filters.amountMax * 100
                ) {
                    matches = false;
                }
                if (
                    filters.categoryIds &&
                    filters.categoryIds.length > 0 &&
                    !filters.categoryIds.includes(
                        transaction.category_id as number,
                    )
                ) {
                    matches = false;
                }
                if (
                    filters.accountIds &&
                    filters.accountIds.length > 0 &&
                    !filters.accountIds.includes(transaction.account_id)
                ) {
                    matches = false;
                }
                if (filters.labelIds && filters.labelIds.length > 0) {
                    const hasMatchingLabel = transaction.label_ids?.some((id) =>
                        filters.labelIds?.includes(id),
                    );
                    if (!hasMatchingLabel) {
                        matches = false;
                    }
                }

                if (matches) {
                    const updated = {
                        ...transaction,
                        ...transactionData,
                        label_ids:
                            label_ids !== undefined
                                ? label_ids.length === 0
                                    ? []
                                    : Array.from(
                                          new Set([
                                              ...(transaction.label_ids || []),
                                              ...label_ids,
                                          ]),
                                      )
                                : transaction.label_ids,
                        updated_at: timestamp,
                    };
                    updates.push(updated);
                }
            }

            if (updates.length > 0) {
                await db.transactions.bulkPut(updates);
            }

            return result.count || 0;
        } catch (error) {
            console.error(
                'Failed to update transactions by filters via API:',
                error,
            );
            throw error;
        }
    }

    async delete(id: string): Promise<void> {
        const transaction = await this.getById(id);

        if (!transaction) {
            throw new Error('Transaction not found');
        }

        const timestamp = new Date().toISOString();
        await db.transactions.delete(transaction.id);
        await db.pending_changes.add({
            store: 'transactions',
            operation: 'delete',
            data: transaction,
            timestamp,
        });
    }

    async updateManyIndividual(
        updates: Array<{ id: string; data: TransactionUpdateData }>,
    ): Promise<void> {
        const timestamp = new Date().toISOString();
        const dbUpdates: Transaction[] = [];
        const pendingChanges: Array<{
            store: string;
            operation: string;
            data: Transaction;
            timestamp: string;
        }> = [];

        for (const { id, data } of updates) {
            const existing = await this.getById(id);

            if (!existing) {
                console.warn(`Transaction ${id} not found, skipping`);
                continue;
            }

            // eslint-disable-next-line @typescript-eslint/no-unused-vars
            const { label_ids, ...transactionData } = data;

            const updated = {
                ...existing,
                ...transactionData,
                updated_at: timestamp,
            } as Transaction;

            dbUpdates.push(updated);
            pendingChanges.push({
                store: 'transactions',
                operation: 'update',
                data: updated,
                timestamp,
            });
        }

        if (dbUpdates.length > 0) {
            await db.transactions.bulkPut(dbUpdates);
            await db.pending_changes.bulkAdd(pendingChanges);
        }
    }

    async deleteMany(ids: string[]): Promise<void> {
        const timestamp = new Date().toISOString();

        for (const id of ids) {
            const transaction = await this.getById(id);

            if (!transaction) {
                console.warn(`Transaction ${id} not found, skipping`);
                continue;
            }

            await db.transactions.delete(transaction.id);
            await db.pending_changes.add({
                store: 'transactions',
                operation: 'delete',
                data: transaction,
                timestamp,
            });
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
                            transaction_date: normalizeDate(t.transaction_date),
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

                return validDecryptedTransactions.some(
                    (existing) =>
                        existing.transaction_date ===
                            importingTx.transaction_date &&
                        Math.abs(existing.amount - importingTx.amount) <
                            0.001 &&
                        existing.description === normalizedDescription,
                );
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
