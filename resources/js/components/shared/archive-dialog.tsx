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
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    /** One bullet per consequence, in the order they matter to the user. */
    consequences: string[];
    archiveUrl: string;
    confirmLabel: string;
    /** Shown when the request comes back rejected. */
    failureMessage: string;
}

/**
 * The confirmation behind every one-way archive. Budgets and savings goals
 * archive on the same terms — read-only, no new data, no way back — so only the
 * copy and the endpoint differ, and both callers hand those in.
 *
 * Deliberately a plain confirmation rather than a typed one: nothing is deleted,
 * so the bulleted list carries the weight of explaining what stops working.
 */
export function ArchiveDialog({
    open,
    onOpenChange,
    title,
    description,
    consequences,
    archiveUrl,
    confirmLabel,
    failureMessage,
}: Props) {
    const [isArchiving, setIsArchiving] = useState(false);

    const archive = () => {
        setIsArchiving(true);

        router.post(
            archiveUrl,
            {},
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                // Archiving is one-way, so a rejected request that left the
                // dialog sitting there silently would read as "it worked".
                onError: () => toast.error(failureMessage),
                onFinish: () => setIsArchiving(false),
            },
        );
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <ul className="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                    {consequences.map((consequence) => (
                        <li key={consequence}>{consequence}</li>
                    ))}
                    <li className="font-medium text-foreground">
                        {__(
                            'This cannot be undone: there is no way to bring it back.',
                        )}
                    </li>
                </ul>

                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isArchiving}>
                        {__('Cancel')}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(event) => {
                            // The dialog would close on its own before the
                            // request goes out, taking the pending state with it.
                            event.preventDefault();
                            archive();
                        }}
                        disabled={isArchiving}
                    >
                        {isArchiving ? __('Archiving...') : confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
