import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { __ } from '@/utils/i18n';
import { FlaskConical } from 'lucide-react';

/**
 * Flags the banks on our curated beta list (`banking.beta_banks`): the ones
 * whose connections we have seen fail more often than the rest.
 *
 * Surfaced as a plain pill rather than a warning: most of these connections do
 * work, and it has to sit inside the bank-picker rows, which are buttons.
 */
export function BetaConnectorBadge() {
    return <Badge variant="warning">{__('Beta')}</Badge>;
}

/**
 * Why the connector is beta decides what the notice can honestly say: a bank
 * whose connection fails more often, or one of our native integrations that
 * is still new.
 */
const NOTICE_COPY = {
    provider: {
        title: 'This bank is still in beta',
        description:
            'Connections to this bank fail or pause more often than with other banks. It usually works, and you can reconnect or disconnect whenever you want.',
    },
    native: {
        title: 'New integration in beta',
        description:
            'We have just built this integration, so syncing may fail more often while we fine-tune it. You can reconnect or disconnect whenever you want.',
    },
} as const;

/**
 * The same signal spelled out, for the confirm step — the one moment the user
 * can still pick a different bank. Says nothing about when a connector leaves
 * beta: that depends on how the bank behaves, not on a date we can promise.
 */
export function BetaConnectorNotice({
    source = 'provider',
}: {
    source?: keyof typeof NOTICE_COPY;
}) {
    const copy = NOTICE_COPY[source];

    return (
        <Alert variant="warning">
            <FlaskConical />
            <AlertTitle>{__(copy.title)}</AlertTitle>
            <AlertDescription>{__(copy.description)}</AlertDescription>
        </Alert>
    );
}
