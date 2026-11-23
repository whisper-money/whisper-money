import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { isExpiringSoon, isWaitingForBank } from '@/lib/banking-connections';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';

const statusConfig: Record<
    BankingConnection['status'],
    {
        label: string;
        variant:
            | 'secondary'
            | 'destructive'
            | 'outline'
            | 'success'
            | 'warning';
        className?: string;
    }
> = {
    active: {
        label: 'Active',
        variant: 'success',
    },
    awaiting_mapping: {
        label: 'Setup Required',
        variant: 'warning',
    },
    pending: {
        label: 'Pending',
        variant: 'secondary',
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
    connection,
}: {
    connection: BankingConnection;
}) {
    const { status, last_synced_at: lastSyncedAt } = connection;

    if (isWaitingForBank(connection)) {
        return <Badge variant="warning">{__('Waiting for the bank')}</Badge>;
    }

    if (isExpiringSoon(connection)) {
        return <Badge variant="warning">{__('Expiring soon')}</Badge>;
    }

    if (status === 'active' && !lastSyncedAt) {
        return (
            <Badge variant="secondary">
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
