import type { Transaction } from '@/types/transaction';
import type { UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { db, isIndexedDbAvailable, withDb } from './dexie-db';

export interface SyncResult {
    success: boolean;
    inserted: number;
    updated: number;
    errors: string[];
}

interface SyncOptions {
    endpoint: string;
    transformFromServer?: (
        data: Record<string, unknown>,
    ) => Record<string, unknown>;
}

const LAST_SYNC_KEY = 'last_sync_transactions';

export class TransactionSyncManager {
    private syncInProgress = false;
    private options: SyncOptions;

    constructor(options: SyncOptions) {
        this.options = options;
    }

    async getLastSyncTime(): Promise<string | null> {
        return withDb(async () => {
            const metadata = await db.sync_metadata.get(LAST_SYNC_KEY);
            return metadata?.value || null;
        }, null);
    }

    async setLastSyncTime(timestamp: string): Promise<void> {
        await withDb<void>(async () => {
            await db.sync_metadata.put({
                key: LAST_SYNC_KEY,
                value: timestamp,
            });
        }, undefined);
    }

    async sync(): Promise<SyncResult> {
        // No offline store to sync into (see PHP-LARAVEL-43). Skip cleanly —
        // empty errors so callers do not treat it as a failure — and avoid the
        // pointless server round-trip that would only populate a missing cache.
        if (!isIndexedDbAvailable()) {
            return { success: true, inserted: 0, updated: 0, errors: [] };
        }

        if (this.syncInProgress) {
            return {
                success: false,
                inserted: 0,
                updated: 0,
                errors: ['Sync already in progress'],
            };
        }

        this.syncInProgress = true;

        const result: SyncResult = {
            success: true,
            inserted: 0,
            updated: 0,
            errors: [],
        };

        try {
            await this.syncFromServer(result);
            await this.setLastSyncTime(new Date().toISOString());
        } catch (error) {
            result.success = false;
            result.errors.push(
                error instanceof Error ? error.message : __('Unknown error'),
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

        const localRecords = await db.transactions.toArray();
        const localMap = new Map(localRecords.map((r) => [r.id, r]));

        const toInsert: Transaction[] = [];
        const toUpdate: Transaction[] = [];

        for (const serverRecord of serverData) {
            const transformed = (
                this.options.transformFromServer
                    ? this.options.transformFromServer(serverRecord)
                    : serverRecord
            ) as Transaction;

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
            await db.transactions.bulkPut(toInsert);
            result.inserted += toInsert.length;
        }

        if (toUpdate.length > 0) {
            await db.transactions.bulkPut(toUpdate);
            result.updated += toUpdate.length;
        }
    }

    async getAll(): Promise<Transaction[]> {
        return withDb<Transaction[]>(() => db.transactions.toArray(), []);
    }

    async getById(id: UUID): Promise<Transaction | null> {
        return withDb<Transaction | null>(
            async () => (await db.transactions.get(id)) || null,
            null,
        );
    }

    async getByAccountId(accountId: UUID): Promise<Transaction[]> {
        return withDb<Transaction[]>(
            () =>
                db.transactions.where('account_id').equals(accountId).toArray(),
            [],
        );
    }

    isSyncing(): boolean {
        return this.syncInProgress;
    }

    async clearAll(): Promise<void> {
        await withDb<void>(async () => {
            await db.transactions.clear();
            await db.sync_metadata.delete(LAST_SYNC_KEY);
        }, undefined);
    }
}
