import { useOnlineStatus } from '@/hooks/use-online-status';
import { identifyUser, resetPostHog } from '@/lib/posthog';
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
        if (!isAuthenticated || !isOnline) {
            return;
        }

        setSyncStatus('success');
        setLastSyncTime(new Date());

        setTimeout(() => {
            setSyncStatus('idle');
        }, 3000);
    }, [isAuthenticated, isOnline]);

    useEffect(() => {
        if (isAuthenticated && isOnline && wasOffline) {
            sync();
        }
        setWasOffline(!isOnline);
    }, [isAuthenticated, isOnline, wasOffline, sync]);

    useEffect(() => {
        if (!isAuthenticated || !currentUser) {
            resetPostHog();
            return;
        }

        lastUserIdRef.current = currentUser.id;

        identifyUser(currentUser.id, {
            email: currentUser.email,
            name: currentUser.name,
        });
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
