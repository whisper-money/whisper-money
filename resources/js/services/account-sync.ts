import { SyncManager } from '@/lib/sync-manager';
import type { Account } from '@/types/account';
import type { UUID } from '@/types/uuid';

class AccountSyncService {
    private syncManager: SyncManager;

    constructor() {
        this.syncManager = new SyncManager({
            storeName: 'accounts',
            endpoint: '/api/sync/accounts',
        });
    }

    async sync() {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<Account[]> {
        return await this.syncManager.getAll<Account>();
    }

    async getById(id: UUID): Promise<Account | null> {
        return await this.syncManager.getById<Account>(id);
    }

    async create(data: Omit<Account, 'id'>): Promise<Account> {
        return await this.syncManager.createLocal<Account>(data as any);
    }

    async update(id: UUID, data: Partial<Account>): Promise<void> {
        await this.syncManager.updateLocal<Account>(id, data);
    }

    async delete(id: UUID): Promise<void> {
        await this.syncManager.deleteLocal(id);
    }

    async getLastSyncTime(): Promise<string | null> {
        return await this.syncManager.getLastSyncTime();
    }

    isSyncing(): boolean {
        return this.syncManager.isSyncing();
    }
}

export const accountSyncService = new AccountSyncService();
