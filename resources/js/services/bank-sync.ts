import { SyncManager } from '@/lib/sync-manager';
import type { Bank } from '@/types/account';

class BankSyncService {
    private syncManager: SyncManager;

    constructor() {
        this.syncManager = new SyncManager({
            storeName: 'banks',
            endpoint: '/api/sync/banks',
        });
    }

    async sync() {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<Bank[]> {
        return await this.syncManager.getAll<Bank>();
    }

    async getById(id: number): Promise<Bank | null> {
        return await this.syncManager.getById<Bank>(id);
    }

    async search(query: string): Promise<Bank[]> {
        const allBanks = await this.getAll();
        const lowerQuery = query.toLowerCase();
        return allBanks.filter((bank) =>
            bank.name.toLowerCase().includes(lowerQuery),
        );
    }

    async getLastSyncTime(): Promise<string | null> {
        return await this.syncManager.getLastSyncTime();
    }

    isSyncing(): boolean {
        return this.syncManager.isSyncing();
    }
}

export const bankSyncService = new BankSyncService();

