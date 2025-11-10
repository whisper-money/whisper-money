import { indexedDBService } from './indexeddb';

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
        const db = await indexedDBService.init();
        const existingStores = Array.from(db.objectStoreNames);
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

