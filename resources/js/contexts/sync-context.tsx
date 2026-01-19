import { useOnlineStatus } from '@/hooks/use-online-status';
import { transactionSyncService } from '@/services/transaction-sync';
import type { User } from '@/types/index.d';
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
    const lastUserIdRef = useRef<string | null>(null);

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
            const result = await transactionSyncService.sync();

            if (result.errors.length > 0) {
                const uniqueFormattedErrors = [
                    ...new Set(result.errors.map(formatErrorMessage)),
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
        if (!isAuthenticated || !currentUser) {
            return;
        }

        // If user changed, clear transactions
        if (lastUserIdRef.current && lastUserIdRef.current !== currentUser.id) {
            transactionSyncService.clearAll();
        }
        lastUserIdRef.current = currentUser.id;
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
