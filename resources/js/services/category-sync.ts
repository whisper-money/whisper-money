import { SyncManager } from '@/lib/sync-manager';
import type { Category } from '@/types/category';

class CategorySyncService {
    private syncManager: SyncManager;

    constructor() {
        this.syncManager = new SyncManager({
            storeName: 'categories',
            endpoint: '/api/sync/categories',
        });
    }

    async sync() {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<Category[]> {
        return await this.syncManager.getAll<Category>();
    }

    async getById(id: number): Promise<Category | null> {
        return await this.syncManager.getById<Category>(id);
    }

    async create(data: Omit<Category, 'id'>): Promise<Category> {
        return await this.syncManager.createLocal<Category>(data as any);
    }

    async update(id: number, data: Partial<Category>): Promise<void> {
        await this.syncManager.updateLocal<Category>(id, data);
    }

    async delete(id: number): Promise<void> {
        await this.syncManager.deleteLocal(id);
    }

    async getLastSyncTime(): Promise<string | null> {
        return await this.syncManager.getLastSyncTime();
    }

    isSyncing(): boolean {
        return this.syncManager.isSyncing();
    }
}

export const categorySyncService = new CategorySyncService();

