import axios from 'axios';
import {
    indexedDBService,
    type StoreName,
    type IndexedDBRecord,
} from './indexeddb';

export interface SyncOptions {
    storeName: StoreName;
    endpoint: string;
    transformFromServer?: (data: any) => any;
    transformToServer?: (data: any) => any;
}

export interface SyncResult {
    success: boolean;
    inserted: number;
    updated: number;
    deleted: number;
    errors: string[];
}

export class SyncManager {
    private syncInProgress = false;
    private lastSyncKey: string;

    constructor(private options: SyncOptions) {
        this.lastSyncKey = `last_sync_${options.storeName}`;
    }

    async getLastSyncTime(): Promise<string | null> {
        return await indexedDBService.getSyncMetadata(this.lastSyncKey);
    }

    async setLastSyncTime(timestamp: string): Promise<void> {
        await indexedDBService.setSyncMetadata(this.lastSyncKey, timestamp);
    }

    async sync(): Promise<SyncResult> {
        if (this.syncInProgress) {
            return {
                success: false,
                inserted: 0,
                updated: 0,
                deleted: 0,
                errors: ['Sync already in progress'],
            };
        }

        this.syncInProgress = true;

        const result: SyncResult = {
            success: true,
            inserted: 0,
            updated: 0,
            deleted: 0,
            errors: [],
        };

        try {
            await this.syncFromServer(result);
            await this.syncToServer(result);

            await indexedDBService.clearPendingChanges(
                this.options.storeName,
            );

            await this.setLastSyncTime(new Date().toISOString());
        } catch (error) {
            result.success = false;
            result.errors.push(
                error instanceof Error ? error.message : 'Unknown error',
            );
        } finally {
            this.syncInProgress = false;
        }

        return result;
    }

    private async syncFromServer(result: SyncResult): Promise<void> {
        const lastSync = await this.getLastSyncTime();

        const params: any = {};
        if (lastSync) {
            params.since = lastSync;
        }

        const response = await axios.get(this.options.endpoint, { params });

        const serverData = response.data.data || response.data;

        if (!Array.isArray(serverData)) {
            throw new Error('Invalid server response format');
        }

        const localRecords = await indexedDBService.getAll<IndexedDBRecord>(
            this.options.storeName,
        );
        const localMap = new Map(localRecords.map((r) => [r.id, r]));

        for (const serverRecord of serverData) {
            const transformed = this.options.transformFromServer
                ? this.options.transformFromServer(serverRecord)
                : serverRecord;

            const localRecord = localMap.get(transformed.id);

            if (!localRecord) {
                await indexedDBService.put(
                    this.options.storeName,
                    transformed,
                );
                result.inserted++;
            } else {
                const serverDate = new Date(transformed.updated_at);
                const localDate = new Date(localRecord.updated_at);

                if (serverDate > localDate) {
                    await indexedDBService.put(
                        this.options.storeName,
                        transformed,
                    );
                    result.updated++;
                }
            }
        }
    }

    private async syncToServer(result: SyncResult): Promise<void> {
        const pendingChanges = await indexedDBService.getPendingChanges(
            this.options.storeName,
        );

        for (const change of pendingChanges) {
            try {
                const data = this.options.transformToServer
                    ? this.options.transformToServer(change.data)
                    : change.data;

                switch (change.operation) {
                    case 'create':
                        await axios.post(this.options.endpoint, data);
                        result.inserted++;
                        break;
                    case 'update':
                        await axios.patch(
                            `${this.options.endpoint}/${change.data.id}`,
                            data,
                        );
                        result.updated++;
                        break;
                    case 'delete':
                        await axios.delete(
                            `${this.options.endpoint}/${change.data.id}`,
                        );
                        result.deleted++;
                        break;
                }
            } catch (error) {
                result.errors.push(
                    `Failed to sync ${change.operation} for ${this.options.storeName}: ${error}`,
                );
            }
        }
    }

    async createLocal<T extends IndexedDBRecord>(
        data: Omit<T, 'id' | 'created_at' | 'updated_at'>,
    ): Promise<T> {
        const timestamp = new Date().toISOString();
        const tempId = Date.now();

        const record = {
            ...data,
            id: tempId,
            created_at: timestamp,
            updated_at: timestamp,
        } as T;

        await indexedDBService.put(this.options.storeName, record);
        await indexedDBService.addPendingChange(
            this.options.storeName,
            'create',
            record,
        );

        return record;
    }

    async updateLocal<T extends IndexedDBRecord>(
        id: number,
        data: Partial<T>,
    ): Promise<void> {
        const existing = await indexedDBService.getById<T>(
            this.options.storeName,
            id,
        );

        if (!existing) {
            throw new Error(`Record ${id} not found in ${this.options.storeName}`);
        }

        const updated = {
            ...existing,
            ...data,
            updated_at: new Date().toISOString(),
        };

        await indexedDBService.put(this.options.storeName, updated);
        await indexedDBService.addPendingChange(
            this.options.storeName,
            'update',
            updated,
        );
    }

    async deleteLocal(id: number): Promise<void> {
        await indexedDBService.delete(this.options.storeName, id);
        await indexedDBService.addPendingChange(
            this.options.storeName,
            'delete',
            { id },
        );
    }

    async getAll<T extends IndexedDBRecord>(): Promise<T[]> {
        return await indexedDBService.getAll<T>(this.options.storeName);
    }

    async getById<T extends IndexedDBRecord>(id: number): Promise<T | null> {
        return await indexedDBService.getById<T>(this.options.storeName, id);
    }

    isSyncing(): boolean {
        return this.syncInProgress;
    }
}

