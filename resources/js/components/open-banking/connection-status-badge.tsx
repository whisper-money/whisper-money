import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';

const statusConfig: Record<
    BankingConnection['status'],
    {
        label: string;
        variant: 'default' | 'secondary' | 'destructive' | 'outline';
    }
> = {
    active: { label: 'Active', variant: 'default' },
    awaiting_mapping: { label: 'Setup Required', variant: 'secondary' },
    pending: { label: 'Pending', variant: 'secondary' },
    expired: { label: 'Expired', variant: 'outline' },
    revoked: { label: 'Revoked', variant: 'outline' },
    error: { label: 'Error', variant: 'destructive' },
};

export function ConnectionStatusBadge({
    status,
    lastSyncedAt,
}: {
    status: BankingConnection['status'];
    lastSyncedAt?: string | null;
}) {
    if (status === 'active' && !lastSyncedAt) {
        return (
            <Badge variant="secondary" className="gap-1">
                <Spinner className="size-3" />
                {__('Syncing')}
            </Badge>
        );
    }

    const config = statusConfig[status];

    return <Badge variant={config.variant}>{__(config.label)}</Badge>;
}
