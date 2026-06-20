import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { __ } from '@/utils/i18n';
import { TriangleAlert } from 'lucide-react';

interface ReplaceConnectionWarningProps {
    acknowledged: boolean;
    onAcknowledgedChange: (acknowledged: boolean) => void;
}

/**
 * Shown when the user is about to authorize a bank they already have a live
 * connection to. Authorizing with the same bank login replaces the existing
 * session, so the previous connection stops working. The checkbox gates the
 * Connect button to avoid breaking a working connection by accident.
 */
export function ReplaceConnectionWarning({
    acknowledged,
    onAcknowledgedChange,
}: ReplaceConnectionWarningProps) {
    return (
        <Alert variant="destructive">
            <TriangleAlert />
            <AlertTitle>
                {__('This will replace your existing connection')}
            </AlertTitle>
            <AlertDescription>
                <p>
                    {__(
                        'You already have an active connection with this bank. If you authorize with the same bank login, the previous connection will stop working. Only continue if you are connecting a different account.',
                    )}
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
