import { store } from '@/actions/App/Http/Controllers/Settings/LabelController';
import { SyncManager } from '@/lib/sync-manager';
import type { Label } from '@/types/label';
import type { UUID } from '@/types/uuid';

class LabelSyncService {
    private syncManager: SyncManager;

    constructor() {
        this.syncManager = new SyncManager({
            storeName: 'labels',
            endpoint: '/api/sync/labels',
        });
    }

    async sync() {
        return await this.syncManager.sync();
    }

    async getAll(): Promise<Label[]> {
        return await this.syncManager.getAll<Label>();
    }

    async getById(id: UUID): Promise<Label | null> {
        return await this.syncManager.getById<Label>(id);
    }

    async create(data: { name: string; color: string }): Promise<Label> {
        const csrfToken = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((row) => row.startsWith('XSRF-TOKEN='))
                ?.split('=')[1] || '',
        );

        const response = await fetch(store().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => null);
            console.error('Failed to create label:', errorData);
            throw new Error(errorData?.message || 'Failed to create label');
        }

        const result = await response.json();
        const label = result.data as Label;

        // Store the label in IndexedDB
        const { db } = await import('@/lib/dexie-db');
        await db.labels.put(label);

        return label;
    }

    async update(id: UUID, data: Partial<Label>): Promise<void> {
        await this.syncManager.updateLocal<Label>(id, data);
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

    async findOrCreate(
        name: string,
        color: string = 'blue',
    ): Promise<Label | null> {
        const labels = await this.getAll();
        const existing = labels.find(
            (l) => l.name.toLowerCase() === name.toLowerCase(),
        );

        if (existing) {
            return existing;
        }

        try {
            return await this.create({ name, color });
        } catch (error) {
            console.error('Failed to create label:', error);
            return null;
        }
    }
}

export const labelSyncService = new LabelSyncService();
