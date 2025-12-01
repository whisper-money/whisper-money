import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useSyncContext } from '@/contexts/sync-context';
import { formatDistanceToNow } from 'date-fns';
import { CloudAlert, CloudCheck, CloudOff, RefreshCw } from 'lucide-react';

export function SyncStatusButton() {
    const { syncStatus, lastSyncTime, isOnline, sync, error } =
        useSyncContext();

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

    const getTooltipText = () => {
        if (syncStatus === 'syncing') {
            return 'Syncing...';
        }

        if (!isOnline) {
            return 'Offline - Click to retry when online';
        }

        if (syncStatus === 'error') {
            return error || 'Sync failed - Click to retry';
        }

        if (lastSyncTime) {
            return `Synced ${formatDistanceToNow(lastSyncTime, { addSuffix: true })}`;
        }

        return 'Not synced yet - Click to sync';
    };

    const getVariant = () => {
        return 'ghost' as const;
    };

    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        variant={getVariant()}
                        size="icon"
                        onClick={sync}
                        disabled={syncStatus === 'syncing' || !isOnline}
                        className={`${syncStatus === 'error' || !isOnline ? 'bg-red-100 dark:bg-red-900' : ''}`}
                    >
                        {getIcon()}
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    <p>{getTooltipText()}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
