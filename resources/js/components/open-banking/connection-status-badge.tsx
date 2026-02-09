import { Badge } from '@/components/ui/badge';
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
    pending: { label: 'Pending', variant: 'secondary' },
    expired: { label: 'Expired', variant: 'outline' },
    revoked: { label: 'Revoked', variant: 'outline' },
    error: { label: 'Error', variant: 'destructive' },
};

export function ConnectionStatusBadge({
    status,
}: {
    status: BankingConnection['status'];
}) {
    const config = statusConfig[status];

    return <Badge variant={config.variant}>{__(config.label)}</Badge>;
}
