import { useOnlineStatus } from '@/hooks/use-online-status';
import { checkDatabaseVersion } from '@/lib/db-migration-helper';
import { db, type PendingChange } from '@/lib/dexie-db';
import { accountBalanceSyncService } from '@/services/account-balance-sync';
import { accountSyncService } from '@/services/account-sync';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { bankSyncService } from '@/services/bank-sync';
import { categorySyncService } from '@/services/category-sync';
import { transactionSyncService } from '@/services/transaction-sync';
import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';

export type SyncStatus = 'idle' | 'syncing' | 'success' | 'error';

interface SyncContextType {
    syncStatus: SyncStatus;
    lastSyncTime: Date | null;
    isOnline: boolean;
    isAuthenticated: boolean;
    sync: () => Promise<void>;
    error: string | null;
    pendingOperationsCount: number;
    pendingOperations: PendingChange[];
    refreshPendingOperations: () => Promise<void>;
}

const SyncContext = createContext<SyncContextType | undefined>(undefined);

function formatErrorMessage(error: string): string {
    if (error.includes('status code 5')) {
        return 'Server is temporarily unavailable. Please try again later.';
    }
    if (
        error.includes('status code 401') ||
        error.includes('status code 403')
    ) {
        return 'Your session has expired. Please refresh the page.';
    }
    if (error.includes('status code 4')) {
        return 'Something went wrong. Please try again.';
    }
    if (error.includes('Network Error') || error.includes('network')) {
        return 'Unable to connect. Check your internet connection.';
    }
    if (error.includes('timeout') || error.includes('Timeout')) {
        return 'The request took too long. Please try again.';
    }
    if (error === 'Sync already in progress') {
        return 'Sync is already running. Please wait.';
    }
    return 'Sync failed. Please try again.';
}

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
    const [pendingOperationsCount, setPendingOperationsCount] = useState(0);
    const [pendingOperations, setPendingOperations] = useState<PendingChange[]>(
        [],
    );
    const syncInProgressRef = useRef(false);

    const refreshPendingOperations = useCallback(async () => {
        try {
            const operations = await db.pending_changes.toArray();
            setPendingOperations(operations);
            setPendingOperationsCount(operations.length);
        } catch (err) {
            console.error('Failed to fetch pending operations:', err);
        }
    }, []);

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
            setError(
                'Unable to sync while offline. Connect to the internet and try again.',
            );
            return;
        }

        if (syncInProgressRef.current) {
            return;
        }

        syncInProgressRef.current = true;
        setSyncStatus('syncing');
        setError(null);

        try {
            const [
                categoriesResult,
                accountsResult,
                accountBalancesResult,
                banksResult,
                automationRulesResult,
                transactionsResult,
            ] = await Promise.all([
                categorySyncService.sync(),
                accountSyncService.sync(),
                accountBalanceSyncService.sync(),
                bankSyncService.sync(),
                automationRuleSyncService.sync(),
                transactionSyncService.sync(),
            ]);

            const allErrors = [
                ...categoriesResult.errors,
                ...accountsResult.errors,
                ...accountBalancesResult.errors,
                ...banksResult.errors,
                ...automationRulesResult.errors,
                ...transactionsResult.errors,
            ];

            if (allErrors.length > 0) {
                const uniqueFormattedErrors = [
                    ...new Set(allErrors.map(formatErrorMessage)),
                ];
                setError(uniqueFormattedErrors.join(' '));
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
            const errorMessage =
                err instanceof Error ? err.message : 'Unknown sync error';
            setError(formatErrorMessage(errorMessage));
            setSyncStatus('error');

            setTimeout(() => {
                setSyncStatus('idle');
            }, 5000);
        } finally {
            syncInProgressRef.current = false;
            await refreshPendingOperations();
        }
    }, [isAuthenticated, isOnline, refreshPendingOperations]);

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

        checkDatabaseVersion().then(({ needsRefresh, missingStores }) => {
            if (needsRefresh) {
                console.warn(
                    'Database needs update. Missing stores:',
                    missingStores,
                    '\nPlease refresh the page with Ctrl+Shift+R (or Cmd+Shift+R on Mac)',
                );
            }
        });

        refreshPendingOperations();
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
                pendingOperationsCount,
                pendingOperations,
                refreshPendingOperations,
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
