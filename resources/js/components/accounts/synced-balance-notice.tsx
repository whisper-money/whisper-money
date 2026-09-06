import { Alert, AlertDescription } from '@/components/ui/alert';
import type { Account } from '@/types/account';
import { __ } from '@/utils/i18n';
import { RefreshCw } from 'lucide-react';

interface SyncedBalanceNoticeProps {
    account?: Account | null;
}

/**
 * A connected account's balances are still the user's to write, but a sync
 * writes one record on the bank's reference date — today — so today's figure
 * and any date still to come get replaced on the next sync. Earlier dates are
 * never touched, which is what makes editing and importing history worth
 * offering on a connected account at all.
 *
 * Renders nothing for a manual account, so the surfaces that mount it read as
 * they always did.
 */
export function SyncedBalanceNotice({ account }: SyncedBalanceNoticeProps) {
    if (!account?.banking_connection_id) {
        return null;
    }

    return (
        <Alert>
            <RefreshCw />
            <AlertDescription>
                {__(
                    "This account is connected to your bank. Every sync overwrites today's figure, and any future date once that day arrives. Earlier dates are yours to edit and import.",
                )}
            </AlertDescription>
        </Alert>
    );
}
