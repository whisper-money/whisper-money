import { Spinner } from '@/components/ui/spinner';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';

const statusConfig: Record<
    BankingConnection['status'],
    {
        label: string;
        dotClass: string;
    }
> = {
    active: { label: 'Active', dotClass: 'bg-green-500' },
    awaiting_mapping: { label: 'Setup Required', dotClass: 'bg-yellow-500' },
    pending: { label: 'Pending', dotClass: 'bg-yellow-500' },
    expired: { label: 'Expired', dotClass: 'bg-gray-400' },
    revoked: { label: 'Revoked', dotClass: 'bg-gray-400' },
    error: { label: 'Error', dotClass: 'bg-red-500' },
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
            <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                <Spinner className="size-3" />
                {__('Syncing')}
            </span>
        );
    }

    const config = statusConfig[status];

    return (
        <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
            <span
                className={`size-2 shrink-0 rounded-full ${config.dotClass}`}
                aria-hidden="true"
            />
            {__(config.label)}
        </span>
    );
}
