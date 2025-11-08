const DB_NAME = 'whisper_money';
const DB_VERSION = 2;

// Force database recreation if needed
export async function resetDatabase(): Promise<void> {
    return new Promise((resolve, reject) => {
        const deleteRequest = indexedDB.deleteDatabase(DB_NAME);
        
        deleteRequest.onsuccess = () => {
            console.log('Database deleted successfully');
            resolve();
        };
        
        deleteRequest.onerror = () => {
            reject(new Error('Failed to delete database'));
        };
        
        deleteRequest.onblocked = () => {
            console.warn('Database deletion blocked. Please close all other tabs.');
            reject(new Error('Database deletion blocked'));
        };
    });
}

export interface IndexedDBRecord {
    id: number;
    user_id?: number | null;
    created_at: string;
    updated_at: string;
    [key: string]: any;
}

export type StoreName = 'categories' | 'accounts' | 'banks' | 'transactions';

class IndexedDBService {
    private db: IDBDatabase | null = null;
    private initPromise: Promise<IDBDatabase> | null = null;

    async checkStoreExists(storeName: StoreName): Promise<boolean> {
        const db = await this.init();
        return db.objectStoreNames.contains(storeName);
    }

    async init(): Promise<IDBDatabase> {
        if (this.db) {
            return this.db;
        }

        if (this.initPromise) {
            return this.initPromise;
        }

        this.initPromise = new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onerror = () => {
                reject(new Error('Failed to open IndexedDB'));
            };

            request.onsuccess = () => {
                this.db = request.result;
                resolve(this.db);
            };

            request.onupgradeneeded = (event) => {
                const db = (event.target as IDBOpenDBRequest).result;

                if (!db.objectStoreNames.contains('categories')) {
                    const categoryStore = db.createObjectStore('categories', {
                        keyPath: 'id',
                    });
                    categoryStore.createIndex('user_id', 'user_id', {
                        unique: false,
                    });
                    categoryStore.createIndex('updated_at', 'updated_at', {
                        unique: false,
                    });
                }

                if (!db.objectStoreNames.contains('accounts')) {
                    const accountStore = db.createObjectStore('accounts', {
                        keyPath: 'id',
                    });
                    accountStore.createIndex('user_id', 'user_id', {
                        unique: false,
                    });
                    accountStore.createIndex('updated_at', 'updated_at', {
                        unique: false,
                    });
                }

                if (!db.objectStoreNames.contains('banks')) {
                    const bankStore = db.createObjectStore('banks', {
                        keyPath: 'id',
                    });
                    bankStore.createIndex('user_id', 'user_id', {
                        unique: false,
                    });
                    bankStore.createIndex('updated_at', 'updated_at', {
                        unique: false,
                    });
                }

                if (!db.objectStoreNames.contains('transactions')) {
                    const transactionStore = db.createObjectStore('transactions', {
                        keyPath: 'id',
                    });
                    transactionStore.createIndex('user_id', 'user_id', {
                        unique: false,
                    });
                    transactionStore.createIndex('account_id', 'account_id', {
                        unique: false,
                    });
                    transactionStore.createIndex('updated_at', 'updated_at', {
                        unique: false,
                    });
                }

                if (!db.objectStoreNames.contains('sync_metadata')) {
                    db.createObjectStore('sync_metadata', { keyPath: 'key' });
                }

                if (!db.objectStoreNames.contains('pending_changes')) {
                    const pendingStore = db.createObjectStore('pending_changes', {
                        keyPath: 'id',
                        autoIncrement: true,
                    });
                    pendingStore.createIndex('store', 'store', {
                        unique: false,
                    });
                    pendingStore.createIndex('timestamp', 'timestamp', {
                        unique: false,
                    });
                }
            };
        });

        return this.initPromise;
    }

    async getAll<T extends IndexedDBRecord>(
        storeName: StoreName,
    ): Promise<T[]> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            try {
                if (!db.objectStoreNames.contains(storeName)) {
                    console.warn(`Object store '${storeName}' not found. Returning empty array.`);
                    resolve([]);
                    return;
                }

                const transaction = db.transaction(storeName, 'readonly');
                const store = transaction.objectStore(storeName);
                const request = store.getAll();

                request.onsuccess = () => {
                    resolve(request.result as T[]);
                };

                request.onerror = () => {
                    reject(new Error(`Failed to get all from ${storeName}`));
                };
            } catch (error) {
                console.error(`Error accessing ${storeName}:`, error);
                resolve([]);
            }
        });
    }

    async getById<T extends IndexedDBRecord>(
        storeName: StoreName,
        id: number,
    ): Promise<T | null> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(storeName, 'readonly');
            const store = transaction.objectStore(storeName);
            const request = store.get(id);

            request.onsuccess = () => {
                resolve(request.result as T | null);
            };

            request.onerror = () => {
                reject(new Error(`Failed to get from ${storeName}`));
            };
        });
    }

    async put<T extends IndexedDBRecord>(
        storeName: StoreName,
        record: T,
    ): Promise<void> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            try {
                if (!db.objectStoreNames.contains(storeName)) {
                    reject(new Error(`Object store '${storeName}' not found. Please refresh the page.`));
                    return;
                }

                const transaction = db.transaction(storeName, 'readwrite');
                const store = transaction.objectStore(storeName);
                const request = store.put(record);

                request.onsuccess = () => {
                    resolve();
                };

                request.onerror = () => {
                    reject(new Error(`Failed to put to ${storeName}`));
                };
            } catch (error) {
                reject(error);
            }
        });
    }

    async putMany<T extends IndexedDBRecord>(
        storeName: StoreName,
        records: T[],
    ): Promise<void> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);

            let completed = 0;
            let hasError = false;

            records.forEach((record) => {
                const request = store.put(record);

                request.onsuccess = () => {
                    completed++;
                    if (completed === records.length && !hasError) {
                        resolve();
                    }
                };

                request.onerror = () => {
                    if (!hasError) {
                        hasError = true;
                        reject(
                            new Error(`Failed to put many to ${storeName}`),
                        );
                    }
                };
            });

            if (records.length === 0) {
                resolve();
            }
        });
    }

    async delete(storeName: StoreName, id: number): Promise<void> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.delete(id);

            request.onsuccess = () => {
                resolve();
            };

            request.onerror = () => {
                reject(new Error(`Failed to delete from ${storeName}`));
            };
        });
    }

    async clear(storeName: StoreName): Promise<void> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction(storeName, 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.clear();

            request.onsuccess = () => {
                resolve();
            };

            request.onerror = () => {
                reject(new Error(`Failed to clear ${storeName}`));
            };
        });
    }

    async getSyncMetadata(key: string): Promise<any> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('sync_metadata', 'readonly');
            const store = transaction.objectStore('sync_metadata');
            const request = store.get(key);

            request.onsuccess = () => {
                resolve(request.result?.value);
            };

            request.onerror = () => {
                reject(new Error('Failed to get sync metadata'));
            };
        });
    }

    async setSyncMetadata(key: string, value: any): Promise<void> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('sync_metadata', 'readwrite');
            const store = transaction.objectStore('sync_metadata');
            const request = store.put({ key, value });

            request.onsuccess = () => {
                resolve();
            };

            request.onerror = () => {
                reject(new Error('Failed to set sync metadata'));
            };
        });
    }

    async addPendingChange(
        storeName: StoreName,
        operation: 'create' | 'update' | 'delete',
        data: any,
    ): Promise<void> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            try {
                const transaction = db.transaction('pending_changes', 'readwrite');
                const store = transaction.objectStore('pending_changes');
                const request = store.add({
                    store: storeName,
                    operation,
                    data,
                    timestamp: new Date().toISOString(),
                });

                request.onsuccess = () => {
                    resolve();
                };

                request.onerror = () => {
                    reject(new Error('Failed to add pending change'));
                };
            } catch (error) {
                reject(error);
            }
        });
    }

    async getPendingChanges(storeName?: StoreName): Promise<any[]> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('pending_changes', 'readonly');
            const store = transaction.objectStore('pending_changes');

            let request: IDBRequest;
            if (storeName) {
                const index = store.index('store');
                request = index.getAll(storeName);
            } else {
                request = store.getAll();
            }

            request.onsuccess = () => {
                resolve(request.result);
            };

            request.onerror = () => {
                reject(new Error('Failed to get pending changes'));
            };
        });
    }

    async clearPendingChanges(storeName?: StoreName): Promise<void> {
        const db = await this.init();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction('pending_changes', 'readwrite');
            const store = transaction.objectStore('pending_changes');

            if (!storeName) {
                const request = store.clear();
                request.onsuccess = () => resolve();
                request.onerror = () =>
                    reject(new Error('Failed to clear pending changes'));
                return;
            }

            const index = store.index('store');
            const request = index.openCursor(IDBKeyRange.only(storeName));

            request.onsuccess = (event) => {
                const cursor = (event.target as IDBRequest).result;
                if (cursor) {
                    cursor.delete();
                    cursor.continue();
                } else {
                    resolve();
                }
            };

            request.onerror = () => {
                reject(new Error('Failed to clear pending changes'));
            };
        });
    }
}

export const indexedDBService = new IndexedDBService();

