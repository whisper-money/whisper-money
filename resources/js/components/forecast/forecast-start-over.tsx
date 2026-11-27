import { store as startOver } from '@/actions/App/Http/Controllers/Forecast/ForecastStartOverController';
import { MoreOptionsMenu } from '@/components/more-options-menu';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { type MouseEvent, useState } from 'react';

/**
 * The forecast header's "More options" menu, with "Start over": after asking,
 * it deletes what the user built in this space's forecast (their edits,
 * yearly payments and alerts), so it is worked out again from their
 * transactions. `onStartedOver` runs once the page is back, to open the
 * review as the first time.
 */
export function ForecastStartOverMenu({
    className,
    onStartedOver,
}: {
    className?: string;
    onStartedOver: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const confirm = (event: MouseEvent) => {
        // Keep the dialog open, and its buttons disabled, until the page is back.
        event.preventDefault();

        router.post(
            startOver().url,
            {},
            {
                onStart: () => setProcessing(true),
                onSuccess: () => {
                    setOpen(false);
                    onStartedOver();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <MoreOptionsMenu className={className}>
                <DropdownMenuItem
                    variant="destructive"
                    onSelect={() => setOpen(true)}
                    data-test="forecast-start-over"
                >
                    {__('Start over')}
                </DropdownMenuItem>
            </MoreOptionsMenu>
            <AlertDialog
                open={open}
                onOpenChange={(next) => !processing && setOpen(next)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {__('Start your forecast over?')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {__(
                                'Your edits, yearly payments and alerts in this space are deleted, and the forecast is worked out again from your transactions. This cannot be undone.',
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={processing}>
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            disabled={processing}
                            onClick={confirm}
                            data-test="forecast-start-over-confirm"
                        >
                            {__('Start over')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
