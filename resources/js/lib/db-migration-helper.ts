import { db } from './dexie-db';

export async function checkDatabaseVersion(): Promise<{
    needsRefresh: boolean;
    missingStores: string[];
}> {
    const requiredStores = [
        'categories',
        'accounts',
        'banks',
        'automation_rules',
        'transactions',
        'sync_metadata',
        'pending_changes',
    ];

    try {
        await db.open();
        const existingStores = db.tables.map((table) => table.name);
        const missingStores = requiredStores.filter(
            (store) => !existingStores.includes(store),
        );

        if (missingStores.length > 0) {
            console.warn(
                'Missing IndexedDB stores:',
                missingStores,
                '\nPlease refresh the page (Ctrl+Shift+R or Cmd+Shift+R)',
            );
        }

        return {
            needsRefresh: missingStores.length > 0,
            missingStores,
        };
    } catch (error) {
        console.error('Failed to check database version:', error);
        return {
            needsRefresh: false,
            missingStores: [],
        };
    }
}
