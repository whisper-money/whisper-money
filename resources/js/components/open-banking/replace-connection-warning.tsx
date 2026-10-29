import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { index as connectionAccounts } from '@/routes/open-banking/connection-accounts';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';

const DISCORD_URL = 'https://discord.gg/m8hUhx6D9D';
const SUPPORT_MAILTO = 'mailto:support@whisper.money';

interface ReplaceConnectionWarningProps {
    /** The live connection to this bank, when it has an accounts screen to link to. */
    existingConnectionId?: string;
    acknowledged: boolean;
    onAcknowledgedChange: (acknowledged: boolean) => void;
}

/**
 * Shown when the user is about to authorize a bank they already have a live
 * connection to. Authorizing with the same bank login replaces the existing
 * session, so the previous connection stops working. The checkbox gates the
 * Connect button to avoid breaking a working connection by accident.
 *
 * "A different account" used to read as "a different bank account", so users
 * added a second account from the same login as a new connection, which then
 * revoked the first one. The copy talks about logins, and points another
 * account from the same login to the existing connection's accounts screen.
 *
 * Only the title and icon are red; the body stays neutral so the explanation
 * and support links read as guidance rather than an error.
 */
export function ReplaceConnectionWarning({
    existingConnectionId,
    acknowledged,
    onAcknowledgedChange,
}: ReplaceConnectionWarningProps) {
    return (
        <Alert className="text-destructive">
            <TriangleAlert />
            <AlertTitle>
                {__('This may replace your existing connection')}
            </AlertTitle>
            <AlertDescription>
                <p>
                    {__(
                        'You already have an active connection with this bank. If you sign in with the same bank login, the previous connection will stop working.',
                    )}{' '}
                    <ManageAccountsHint
                        existingConnectionId={existingConnectionId}
                    />{' '}
                    <strong>
                        {__(
                            "Only continue if you are signing in with a different login, like a partner's.",
                        )}
                    </strong>
                </p>
                <p className="my-1">
                    {__('Having trouble with this connection?')}{' '}
                    <a
                        href={DISCORD_URL}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primary underline"
                    >
                        {__('Join the community')}
                    </a>{' '}
                    {__('or')}{' '}
                    <a href={SUPPORT_MAILTO} className="text-primary underline">
                        {__('mail support')}
                    </a>
                    .
                </p>
                <label className="mt-2 flex items-start gap-2 text-foreground">
                    <Checkbox
                        checked={acknowledged}
                        onCheckedChange={(checked) =>
                            onAcknowledgedChange(checked === true)
                        }
                    />
                    <span>{__('I will use a different bank login')}</span>
                </label>
            </AlertDescription>
        </Alert>
    );
}

/**
 * The sentence sending another account from the same login to Manage Accounts.
 * The link sits inside one translated sentence, at `:link`, so each language
 * keeps its own word order around it.
 */
function ManageAccountsHint({
    existingConnectionId,
}: {
    existingConnectionId?: string;
}) {
    const [before, after] = __(
        'To add another account from that login, use :link on your existing connection instead.',
    ).split(':link');
    const label = __('Manage Accounts');

    return (
        <>
            {before}
            {existingConnectionId ? (
                <Link
                    href={connectionAccounts.url(existingConnectionId)}
                    className="text-primary underline"
                >
                    {label}
                </Link>
            ) : (
                label
            )}
            {after}
        </>
    );
}
