import type { UUID } from '@/types/uuid';
import axios, { AxiosError } from 'axios';
import { uuidv7 } from 'uuidv7';
import { db } from './dexie-db';

export type StoreName =
    | 'categories'
    | 'accounts'
    | 'banks'
    | 'automation_rules'
    | 'transactions';

export interface IndexedDBRecord {
    id: UUID;
    user_id?: UUID | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}

export interface SyncOptions {
    storeName: StoreName;
    endpoint: string;
    transformFromServer?: (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
    transformToServer?: (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
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
        const metadata = await db.sync_metadata.get(this.lastSyncKey);
        return metadata?.value || null;
    }

    async setLastSyncTime(timestamp: string): Promise<void> {
        await db.sync_metadata.put({
            key: this.lastSyncKey,
            value: timestamp,
        });
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
            const successfulChangeIds = await this.syncToServer(result);

            if (successfulChangeIds.length > 0) {
                await db.pending_changes.bulkDelete(successfulChangeIds);
            }

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

        const params: Record<string, string> = {};
        if (lastSync) {
            params.since = lastSync;
        }

        const response = await axios.get(this.options.endpoint, { params });

        const serverData = response.data.data || response.data;

        if (!Array.isArray(serverData)) {
            throw new Error('Invalid server response format');
        }

        const table = db[this.options.storeName];
        const localRecords = await table.toArray();
        const localMap = new Map(
            localRecords.map((r) => [
                (r as IndexedDBRecord).id,
                r as IndexedDBRecord,
            ]),
        );

        const toInsert: Record<string, unknown>[] = [];
        const toUpdate: Record<string, unknown>[] = [];

        for (const serverRecord of serverData) {
            const transformed = this.options.transformFromServer
                ? this.options.transformFromServer(serverRecord)
                : serverRecord;

            const localRecord = localMap.get(transformed.id);

            if (!localRecord) {
                toInsert.push(transformed);
            } else {
                const serverDate = new Date(transformed.updated_at);
                const localDate = new Date(localRecord.updated_at);

                if (serverDate > localDate) {
                    toUpdate.push(transformed);
                }
            }
        }

        if (toInsert.length > 0) {
            await table.bulkPut(toInsert);
            result.inserted += toInsert.length;
        }

        if (toUpdate.length > 0) {
            await table.bulkPut(toUpdate);
            result.updated += toUpdate.length;
        }
    }

    private async syncToServer(result: SyncResult): Promise<number[]> {
        const pendingChanges = await db.pending_changes
            .where('store')
            .equals(this.options.storeName)
            .toArray();

        const successfulChangeIds: number[] = [];

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
                        try {
                            await axios.patch(
                                `${this.options.endpoint}/${change.data.id}`,
                                data,
                            );
                            result.updated++;
                        } catch (updateError) {
                            if (
                                updateError instanceof AxiosError &&
                                updateError.response?.status === 404
                            ) {
                                await axios.post(this.options.endpoint, data);
                                result.inserted++;
                            } else {
                                throw updateError;
                            }
                        }
                        break;
                    case 'delete':
                        try {
                            await axios.delete(
                                `${this.options.endpoint}/${change.data.id}`,
                            );
                            result.deleted++;
                        } catch (deleteError) {
                            if (
                                deleteError instanceof AxiosError &&
                                deleteError.response?.status === 404
                            ) {
                                result.deleted++;
                            } else {
                                throw deleteError;
                            }
                        }
                        break;
                }

                if (change.id !== undefined) {
                    successfulChangeIds.push(change.id);
                }
            } catch (error) {
                result.errors.push(
                    `Failed to sync ${change.operation} for ${this.options.storeName}: ${error}`,
                );
            }
        }

        return successfulChangeIds;
    }

    async createLocal<T extends IndexedDBRecord>(
        data: Omit<T, 'id' | 'created_at' | 'updated_at'>,
    ): Promise<T> {
        const timestamp = new Date().toISOString();
        const id = uuidv7();

        const record = {
            ...data,
            id,
            created_at: timestamp,
            updated_at: timestamp,
        } as T;

        const table = db[this.options.storeName];
        await table.put(record);
        await db.pending_changes.add({
            store: this.options.storeName,
            operation: 'create',
            data: record,
            timestamp,
        });

        return record;
    }

    async updateLocal<T extends IndexedDBRecord>(
        id: UUID,
        data: Partial<T>,
    ): Promise<void> {
        const table = db[this.options.storeName];
        const existing = await table.get(id);

        if (!existing) {
            throw new Error(
                `Record ${id} not found in ${this.options.storeName}`,
            );
        }

        const timestamp = new Date().toISOString();
        const updated = {
            ...existing,
            ...data,
            updated_at: timestamp,
        };

        await table.put(updated);
        await db.pending_changes.add({
            store: this.options.storeName,
            operation: 'update',
            data: updated,
            timestamp,
        });
    }

    async deleteLocal(id: UUID): Promise<void> {
        const timestamp = new Date().toISOString();
        const table = db[this.options.storeName];
        await table.delete(id);
        await db.pending_changes.add({
            store: this.options.storeName,
            operation: 'delete',
            data: { id },
            timestamp,
        });
    }

    async getAll<T extends IndexedDBRecord>(): Promise<T[]> {
        const table = db[this.options.storeName];
        return (await table.toArray()) as T[];
    }

    async getById<T extends IndexedDBRecord>(id: UUID): Promise<T | null> {
        const table = db[this.options.storeName];
        return ((await table.get(id)) as T) || null;
    }

    isSyncing(): boolean {
        return this.syncInProgress;
    }
}
