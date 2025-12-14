import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSyncContext } from '@/contexts/sync-context';
import { formatDistanceToNow } from 'date-fns';
import {
    CloudAlert,
    CloudCheck,
    CloudOff,
    List,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import { PendingOperationsDialog } from './pending-operations-dialog';

export function SyncStatusButton() {
    const {
        syncStatus,
        lastSyncTime,
        isOnline,
        sync,
        error,
        pendingOperationsCount,
        pendingOperations,
    } = useSyncContext();
    const [showPendingDialog, setShowPendingDialog] = useState(false);
    const [isMenuOpen, setIsMenuOpen] = useState(false);

    const getIcon = () => {
        if (syncStatus === 'syncing') {
            return <RefreshCw className="h-4 w-4 animate-spin" />;
        }

        if (syncStatus === 'error') {
            return <CloudAlert className="h-4 w-4" />;
        }

        if (!isOnline) {
            return <CloudOff className="h-4 w-4" />;
        }

        return <CloudCheck className="h-4 w-4" />;
    };

    const getLastSyncText = () => {
        if (lastSyncTime) {
            return `Synced ${formatDistanceToNow(lastSyncTime, { addSuffix: true })}`;
        }
        return 'Not synced yet';
    };

    const getStatusText = () => {
        if (syncStatus === 'syncing') {
            return 'Syncing...';
        }
        if (!isOnline) {
            return 'Offline';
        }
        if (syncStatus === 'error') {
            return error || 'Sync failed';
        }
        return getLastSyncText();
    };

    const handleSyncNow = () => {
        sync();
    };

    return (
        <>
            <DropdownMenu open={isMenuOpen} onOpenChange={setIsMenuOpen}>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="icon"
                        className={`relative ${isMenuOpen ? 'bg-accent' : ''} ${syncStatus === 'error' || !isOnline ? 'bg-red-100 dark:bg-red-900' : ''}`}
                    >
                        {getIcon()}
                        {pendingOperationsCount > 0 && (
                            <span className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-medium text-primary-foreground">
                                {pendingOperationsCount > 99
                                    ? '99+'
                                    : pendingOperationsCount}
                            </span>
                        )}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel className="font-normal">
                        <div className="flex flex-col gap-1">
                            <p className="text-xs font-medium">
                                {getStatusText()}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {pendingOperationsCount} pending{' '}
                                {pendingOperationsCount === 1
                                    ? 'operation'
                                    : 'operations'}
                            </p>
                        </div>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onClick={(e) => {
                            e.preventDefault();
                            handleSyncNow();
                        }}
                        disabled={syncStatus === 'syncing' || !isOnline}
                    >
                        <RefreshCw
                            className={`mr-2 h-4 w-4 ${syncStatus === 'syncing' ? 'animate-spin' : ''}`}
                        />
                        Sync now
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onClick={() => setShowPendingDialog(true)}
                    >
                        <List className="mr-2 h-4 w-4" />
                        View pending operations
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <PendingOperationsDialog
                open={showPendingDialog}
                onOpenChange={setShowPendingDialog}
                operations={pendingOperations}
                onSyncNow={handleSyncNow}
                syncStatus={syncStatus}
                isOnline={isOnline}
            />
        </>
    );
}
