import React, {
    createContext,
    useContext,
    useState,
    useEffect,
    useCallback,
    type ReactNode,
} from 'react';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { categorySyncService } from '@/services/category-sync';
import { accountSyncService } from '@/services/account-sync';
import { bankSyncService } from '@/services/bank-sync';

export type SyncStatus = 'idle' | 'syncing' | 'success' | 'error';

interface SyncContextType {
    syncStatus: SyncStatus;
    lastSyncTime: Date | null;
    isOnline: boolean;
    sync: () => Promise<void>;
    error: string | null;
}

const SyncContext = createContext<SyncContextType | undefined>(undefined);

const SYNC_INTERVAL = 5 * 60 * 1000;

export function SyncProvider({ children }: { children: ReactNode }) {
    const isOnline = useOnlineStatus();
    const [syncStatus, setSyncStatus] = useState<SyncStatus>('idle');
    const [lastSyncTime, setLastSyncTime] = useState<Date | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [wasOffline, setWasOffline] = useState(!isOnline);

    const sync = useCallback(async () => {
        if (!isOnline) {
            setError('Cannot sync while offline');
            return;
        }

        if (syncStatus === 'syncing') {
            return;
        }

        setSyncStatus('syncing');
        setError(null);

        try {
            const [categoriesResult, accountsResult, banksResult] =
                await Promise.all([
                    categorySyncService.sync(),
                    accountSyncService.sync(),
                    bankSyncService.sync(),
                ]);

            const allErrors = [
                ...categoriesResult.errors,
                ...accountsResult.errors,
                ...banksResult.errors,
            ];

            if (allErrors.length > 0) {
                setError(allErrors.join(', '));
                setSyncStatus('error');
            } else {
                setSyncStatus('success');
                setLastSyncTime(new Date());

                setTimeout(() => {
                    setSyncStatus('idle');
                }, 3000);
            }
        } catch (err) {
            console.error('Sync failed:', err);
            setError(
                err instanceof Error ? err.message : 'Unknown sync error',
            );
            setSyncStatus('error');

            setTimeout(() => {
                setSyncStatus('idle');
            }, 5000);
        }
    }, [isOnline, syncStatus]);

    useEffect(() => {
        if (isOnline && wasOffline) {
            sync();
        }
        setWasOffline(!isOnline);
    }, [isOnline, wasOffline, sync]);

    useEffect(() => {
        if (!isOnline) {
            return;
        }

        const interval = setInterval(() => {
            sync();
        }, SYNC_INTERVAL);

        return () => clearInterval(interval);
    }, [isOnline, sync]);

    useEffect(() => {
        sync();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <SyncContext.Provider
            value={{
                syncStatus,
                lastSyncTime,
                isOnline,
                sync,
                error,
            }}
        >
            {children}
        </SyncContext.Provider>
    );
}

export function useSyncContext() {
    const context = useContext(SyncContext);
    if (context === undefined) {
        throw new Error('useSyncContext must be used within a SyncProvider');
    }
    return context;
}

