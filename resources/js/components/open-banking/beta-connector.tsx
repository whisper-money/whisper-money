import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { __ } from '@/utils/i18n';
import { FlaskConical } from 'lucide-react';

/** Matches the badge, so the pill and the notice read as the same signal. */
const AMBER =
    'border-amber-500/20 bg-amber-500/10 text-amber-700 dark:text-amber-300';

/**
 * The provider marks some of its bank connectors as beta, and those are the
 * ones that break: over a week of real calls they failed eleven times as often
 * as the stable ones, and the provider leaves them out of its own status page,
 * so nobody reports their outages either.
 *
 * Surfaced as a plain pill rather than a warning: most of these connections do
 * work, and it has to sit inside the bank-picker rows, which are buttons.
 */
export function BetaConnectorBadge() {
    return (
        <Badge variant="secondary" className={AMBER}>
            {__('Beta')}
        </Badge>
    );
}

/**
 * Who flags the connector as beta decides what the notice can honestly say:
 * the bank-aggregator's own flag, or one of our native integrations that is
 * still new.
 */
const NOTICE_COPY = {
    provider: {
        title: 'This bank is still in beta',
        description:
            'Our banking provider marks this connection as beta, so syncing can fail or pause more often than with other banks. It usually works, and you can reconnect or disconnect whenever you want.',
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
 * beta: for the aggregator's connectors that is the provider's call, not ours.
 */
export function BetaConnectorNotice({
    source = 'provider',
}: {
    source?: keyof typeof NOTICE_COPY;
}) {
    const copy = NOTICE_COPY[source];

    return (
        <Alert className={AMBER}>
            <FlaskConical />
            <AlertTitle>{__(copy.title)}</AlertTitle>
            <AlertDescription className="text-amber-700 dark:text-amber-300">
                {__(copy.description)}
            </AlertDescription>
        </Alert>
    );
}
