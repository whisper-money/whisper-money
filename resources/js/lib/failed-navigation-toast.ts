import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { wasPrefetched } from './prefetch-tracker';

/**
 * Tells the user when a navigation or a save died on the network.
 *
 * Inertia's only response to a transport failure is to stop: the progress bar
 * retracts, `processing` clears, and nothing else happens. So a click goes nowhere
 * and a save silently does not save - one of the failures in production was a
 * `PATCH /settings/accounts/{uuid}`, which is a change the user believed they had
 * made.
 *
 * Stays quiet for requests nobody asked for. A hover prefetch that fails costs the
 * user nothing, and saying so would be noise about a page they may never open.
 *
 * Deliberately does not call `preventDefault()`: that would skip the cleanup Inertia
 * runs for a failed prefetch, leaving a dead entry that a later click waits on
 * forever.
 */
export function installFailedNavigationToast(): void {
    router.on('networkError', (event) => {
        const failedUrl = (event.detail.error as { url?: string }).url;

        if (failedUrl !== undefined && wasPrefetched(failedUrl)) {
            return;
        }

        toast.error(
            __('We could not reach the server. Check your connection.'),
            {
                id: 'network-error',
            },
        );
    });
}
