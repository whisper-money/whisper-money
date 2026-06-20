import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { __ } from '@/utils/i18n';
import { TriangleAlert } from 'lucide-react';

const DISCORD_URL = 'https://discord.gg/m8hUhx6D9D';
const SUPPORT_MAILTO = 'mailto:support@whisper.money';

interface ReplaceConnectionWarningProps {
    acknowledged: boolean;
    onAcknowledgedChange: (acknowledged: boolean) => void;
}

/**
 * Shown when the user is about to authorize a bank they already have a live
 * connection to. Authorizing with the same bank login replaces the existing
 * session, so the previous connection stops working. The checkbox gates the
 * Connect button to avoid breaking a working connection by accident.
 *
 * Only the title and icon are red; the body stays neutral so the explanation
 * and support links read as guidance rather than an error.
 */
export function ReplaceConnectionWarning({
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
                        'You already have an active connection with this bank. If you authorize with the same bank login, the previous connection will stop working. Only continue if you are connecting a different account.',
                    )}
                </p>
                <p>{__('Having trouble with this connection?')}</p>
                <p className="flex flex-wrap gap-x-4">
                    <a
                        href={DISCORD_URL}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primary underline"
                    >
                        {__('Join the community')}
                    </a>
                    <a href={SUPPORT_MAILTO} className="text-primary underline">
                        {__('Email support')}
                    </a>
                </p>
                <label className="mt-2 flex items-start gap-2 text-foreground">
                    <Checkbox
                        checked={acknowledged}
                        onCheckedChange={(checked) =>
                            onAcknowledgedChange(checked === true)
                        }
                    />
                    <span>
                        {__(
                            'I understand the existing connection may stop working.',
                        )}
                    </span>
                </label>
            </AlertDescription>
        </Alert>
    );
}
