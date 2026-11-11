import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { ShieldAlert } from 'lucide-react';
import { type SafeModeReason } from './safe-mode';

/**
 * Shown instead of the user widgets in safe mode: why they are not loaded,
 * and a way back to loading them.
 */
export function SafeModeNotice({
    reason,
    onExit,
}: {
    reason: SafeModeReason;
    onExit: () => void;
}) {
    return (
        <Alert data-slot="user-widgets-safe-mode">
            <ShieldAlert />
            <AlertTitle>
                {__('Safe mode: your widgets are not loaded')}
            </AlertTitle>
            <AlertDescription className="gap-2">
                <p>
                    {reason === 'unfinished'
                        ? __(
                              'The last time this dashboard opened, its widgets did not finish loading. If one of them froze the page, remove it from here.',
                          )
                        : __(
                              'Widgets written by your agent stay off until you load them, so you can remove one that is causing problems.',
                          )}
                </p>
                <Button variant="outline" size="sm" onClick={onExit}>
                    {__('Load widgets')}
                </Button>
            </AlertDescription>
        </Alert>
    );
}
