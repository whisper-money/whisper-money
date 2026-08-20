import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';

const statusConfig: Record<
    BankingConnection['status'],
    {
        label: string;
        variant: 'default' | 'secondary' | 'destructive' | 'outline';
        className?: string;
    }
> = {
    active: {
        label: 'Active',
        variant: 'default',
        className:
            'bg-green-100 text-green-800 border-green-200 dark:bg-green-500/10 dark:text-green-400 dark:border-green-500/20',
    },
    awaiting_mapping: {
        label: 'Setup Required',
        variant: 'secondary',
        className:
            'dark:bg-yellow-900/60 dark:text-yellow-200 dark:border-yellow-700/50',
    },
    pending: {
        label: 'Pending',
        variant: 'secondary',
        className:
            'dark:bg-yellow-900/60 dark:text-yellow-200 dark:border-yellow-700/50',
    },
    expired: {
        label: 'Expired',
        variant: 'outline',
        className: 'dark:text-muted-foreground',
    },
    revoked: {
        label: 'Revoked',
        variant: 'outline',
        className: 'dark:text-muted-foreground',
    },
    error: { label: 'Error', variant: 'destructive' },
};

export function ConnectionStatusBadge({
    status,
    lastSyncedAt,
    errorMessage,
}: {
    status: BankingConnection['status'];
    lastSyncedAt?: string | null;
    errorMessage?: string | null;
}) {
    // An active connection that never synced *and* already recorded an error is
    // not syncing: the bank refused us and the job stopped. Only the message is
    // stored, so last_synced_at stays null forever and the spinner would too.
    if (status === 'active' && !lastSyncedAt && errorMessage) {
        return (
            <Badge
                variant="secondary"
                className={statusConfig.pending.className}
            >
                {__('Waiting for the bank')}
            </Badge>
        );
    }

    if (status === 'active' && !lastSyncedAt) {
        return (
            <Badge
                variant="secondary"
                className="gap-1 border-green-200 bg-green-100 text-green-800 dark:border-yellow-700/50 dark:bg-yellow-900/60 dark:text-yellow-200"
            >
                <Spinner className="size-3" />
                {__('Syncing')}
            </Badge>
        );
    }

    const config = statusConfig[status];

    return (
        <Badge variant={config.variant} className={config.className}>
            {__(config.label)}
        </Badge>
    );
}
