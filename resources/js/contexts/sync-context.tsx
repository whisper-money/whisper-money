import React, {
    createContext,
    useContext,
    useState,
    useEffect,
    useCallback,
    type ReactNode,
} from 'react';
import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { categorySyncService } from '@/services/category-sync';
import { accountSyncService } from '@/services/account-sync';
import { bankSyncService } from '@/services/bank-sync';
import { transactionSyncService } from '@/services/transaction-sync';

export type SyncStatus = 'idle' | 'syncing' | 'success' | 'error';

interface SyncContextType {
    syncStatus: SyncStatus;
    lastSyncTime: Date | null;
    isOnline: boolean;
    isAuthenticated: boolean;
    sync: () => Promise<void>;
    error: string | null;
}

const SyncContext = createContext<SyncContextType | undefined>(undefined);

const SYNC_INTERVAL = 5 * 60 * 1000;

interface SyncProviderProps {
    children: ReactNode;
    initialIsAuthenticated: boolean;
}

export function SyncProvider({
    children,
    initialIsAuthenticated,
}: SyncProviderProps) {
    const isOnline = useOnlineStatus();
    const [isAuthenticated, setIsAuthenticated] = useState(
        initialIsAuthenticated,
    );
    const [syncStatus, setSyncStatus] = useState<SyncStatus>('idle');
    const [lastSyncTime, setLastSyncTime] = useState<Date | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [wasOffline, setWasOffline] = useState(!isOnline);

    useEffect(() => {
        const unsubscribe = router.on('navigate', (event) => {
            const page = event.detail.page as Page<{
                auth?: { user?: unknown };
            }>;

            setIsAuthenticated(Boolean(page.props?.auth?.user));
        });

        return () => {
            unsubscribe();
        };
    }, []);

    useEffect(() => {
        if (isAuthenticated) {
            return;
        }

        setSyncStatus('idle');
        setLastSyncTime(null);
        setError(null);
    }, [isAuthenticated]);

    const sync = useCallback(async () => {
        if (!isAuthenticated) {
            return;
        }

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
            const [categoriesResult, accountsResult, banksResult, transactionsResult] =
                await Promise.all([
                    categorySyncService.sync(),
                    accountSyncService.sync(),
                    bankSyncService.sync(),
                    transactionSyncService.sync(),
                ]);

            const allErrors = [
                ...categoriesResult.errors,
                ...accountsResult.errors,
                ...banksResult.errors,
                ...transactionsResult.errors,
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
    }, [isAuthenticated, isOnline, syncStatus]);

    useEffect(() => {
        if (isAuthenticated && isOnline && wasOffline) {
            sync();
        }
        setWasOffline(!isOnline);
    }, [isAuthenticated, isOnline, wasOffline, sync]);

    useEffect(() => {
        if (!isOnline || !isAuthenticated) {
            return;
        }

        const interval = setInterval(() => {
            sync();
        }, SYNC_INTERVAL);

        return () => clearInterval(interval);
    }, [isAuthenticated, isOnline, sync]);

    useEffect(() => {
        if (!isAuthenticated) {
            return;
        }

        sync();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAuthenticated]);

    return (
        <SyncContext.Provider
            value={{
                syncStatus,
                lastSyncTime,
                isOnline,
                isAuthenticated,
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

