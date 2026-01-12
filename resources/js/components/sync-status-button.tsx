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
import { CloudAlert, CloudCheck, CloudOff, RefreshCw } from 'lucide-react';
import { useState } from 'react';

export function SyncStatusButton() {
    const { syncStatus, lastSyncTime, isOnline, sync, error } =
        useSyncContext();
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
        <DropdownMenu open={isMenuOpen} onOpenChange={setIsMenuOpen}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className={`relative ${isMenuOpen ? 'bg-accent' : ''} ${syncStatus === 'error' || !isOnline ? 'bg-red-100 dark:bg-red-900' : ''}`}
                >
                    {getIcon()}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="font-normal">
                    <p className="text-xs font-medium">{getStatusText()}</p>
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
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
