import { useOnlineStatus } from '@/hooks/use-online-status';
import { checkDatabaseVersion } from '@/lib/db-migration-helper';
import { db, type PendingChange } from '@/lib/dexie-db';
import { handleUserChange } from '@/lib/user-session-storage';
import { accountBalanceSyncService } from '@/services/account-balance-sync';
import { accountSyncService } from '@/services/account-sync';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { bankSyncService } from '@/services/bank-sync';
import { categorySyncService } from '@/services/category-sync';
import { labelSyncService } from '@/services/label-sync';
import { transactionSyncService } from '@/services/transaction-sync';
import type { User } from '@/types/index.d';
import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useLiveQuery } from 'dexie-react-hooks';
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
    initialUser: User | null;
}

export function SyncProvider({
    children,
    initialIsAuthenticated,
    initialUser,
}: SyncProviderProps) {
    const isOnline = useOnlineStatus();
    const [isAuthenticated, setIsAuthenticated] = useState(
        initialIsAuthenticated,
    );
    const [currentUser, setCurrentUser] = useState<User | null>(initialUser);
    const [syncStatus, setSyncStatus] = useState<SyncStatus>('idle');
    const [lastSyncTime, setLastSyncTime] = useState<Date | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [wasOffline, setWasOffline] = useState(!isOnline);
    const syncInProgressRef = useRef(false);
    const userChangeCheckedRef = useRef(false);

    const pendingOperations =
        useLiveQuery(() => db.pending_changes.toArray(), []) || [];
    const pendingOperationsCount = pendingOperations.length;

    const refreshPendingOperations = useCallback(async () => {
        // No-op: useLiveQuery handles reactivity automatically
    }, []);

    useEffect(() => {
        const unsubscribe = router.on('navigate', (event) => {
            const page = event.detail.page as Page<{
                auth?: { user?: User };
            }>;

            const user = page.props?.auth?.user ?? null;
            setIsAuthenticated(Boolean(user));
            setCurrentUser(user);
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
                labelsResult,
                transactionsResult,
            ] = await Promise.all([
                categorySyncService.sync(),
                accountSyncService.sync(),
                accountBalanceSyncService.sync(),
                bankSyncService.sync(),
                automationRuleSyncService.sync(),
                labelSyncService.sync(),
                transactionSyncService.sync(),
            ]);

            const allErrors = [
                ...categoriesResult.errors,
                ...accountsResult.errors,
                ...accountBalancesResult.errors,
                ...banksResult.errors,
                ...automationRulesResult.errors,
                ...labelsResult.errors,
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
        }
    }, [isAuthenticated, isOnline]);

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
        if (!isAuthenticated || !currentUser) {
            return;
        }

        const checkUserAndSync = async () => {
            if (userChangeCheckedRef.current) {
                return;
            }

            userChangeCheckedRef.current = true;

            const wasCleared = await handleUserChange(currentUser.id);

            if (wasCleared) {
                window.location.reload();
                return;
            }

            const { needsRefresh, missingStores } =
                await checkDatabaseVersion();

            if (needsRefresh) {
                console.warn(
                    'Database needs update. Missing stores:',
                    missingStores,
                    '\nPlease refresh the page with Ctrl+Shift+R (or Cmd+Shift+R on Mac)',
                );
            }

            sync();
        };

        checkUserAndSync();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAuthenticated, currentUser]);

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
