import { db } from '@/lib/dexie-db';
import { SyncManager } from '@/lib/sync-manager';
import type { AccountBalance } from '@/types/account';

class AccountBalanceSyncService {
    private syncManager: SyncManager;

    constructor() {
        this.syncManager = new SyncManager({
            storeName: 'account_balances',
            endpoint: '/api/sync/account-balances',
        });
    }

    async sync(): Promise<void> {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<AccountBalance[]> {
        return await this.syncManager.getAll<AccountBalance>();
    }

    async getById(id: string): Promise<AccountBalance | null> {
        return (await db.account_balances.get(id)) || null;
    }

    async getByAccountId(accountId: number): Promise<AccountBalance[]> {
        try {
            const allBalances = await this.getAll();
            return allBalances.filter((b) => b.account_id === accountId);
        } catch (error) {
            console.warn('Failed to get balances from IndexedDB:', error);
            return [];
        }
    }

    async create(
        data: Omit<AccountBalance, 'id' | 'created_at' | 'updated_at'>,
    ): Promise<AccountBalance> {
        return await this.syncManager.createLocal<AccountBalance>(data);
    }

    async createMany(
        balances: Omit<AccountBalance, 'id' | 'created_at' | 'updated_at'>[],
    ): Promise<AccountBalance[]> {
        try {
            const created: AccountBalance[] = [];

            for (const data of balances) {
                const result = await this.updateOrCreate(
                    data.account_id,
                    data.balance_date,
                    data.balance,
                );
                created.push(result);
            }

            return created;
        } catch (error) {
            console.error('Failed to create balances:', error);
            throw new Error(
                'Failed to save balances locally. Please refresh the page and try again.',
            );
        }
    }

    async update(id: string, data: Partial<AccountBalance>): Promise<void> {
        const existing = await this.getById(id);
        if (!existing) {
            throw new Error('Balance not found');
        }

        return await this.syncManager.update<AccountBalance>(
            id.toString(),
            data,
        );
    }

    async updateOrCreate(
        accountId: number,
        balanceDate: string,
        balance: number,
    ): Promise<AccountBalance> {
        try {
            const existing = await db.account_balances
                .where({ account_id: accountId, balance_date: balanceDate })
                .first();

            if (existing) {
                const timestamp = new Date().toISOString();
                const updated = {
                    ...existing,
                    balance,
                    updated_at: timestamp,
                };

                await db.account_balances.put(updated);
                await db.pending_changes.add({
                    store: 'account_balances',
                    operation: 'update',
                    data: { id: existing.id, balance },
                    timestamp,
                });

                return updated;
            } else {
                return await this.create({
                    account_id: accountId,
                    balance_date: balanceDate,
                    balance,
                });
            }
        } catch (error) {
            console.error('Failed to update or create balance:', error);
            throw new Error('Failed to save balance. Please try again.');
        }
    }

    async delete(id: string): Promise<void> {
        return await this.syncManager.delete(id);
    }
}

export const accountBalanceSyncService = new AccountBalanceSyncService();
