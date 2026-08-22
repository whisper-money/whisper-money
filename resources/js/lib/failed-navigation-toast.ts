import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { isLeavingPage } from './leave-page';
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
 * user nothing, and saying so would be noise about a page they may never open. The
 * same goes for whatever a full page navigation of our own aborts on the way out.
 *
 * Deliberately does not call `preventDefault()`: that would skip the cleanup Inertia
 * runs for a failed prefetch, leaving a dead entry that a later click waits on
 * forever.
 */
export function installFailedNavigationToast(): void {
    router.on('networkError', (event) => {
        // A request our own departure killed did not fail. `leavePage` aborts
        // whatever is in flight - most visibly the 4s poll the onboarding
        // create-account step runs - and the browser reports that abort as a
        // transport failure, so tapping a bank ended in "check your connection"
        // while the bank's own page was already opening. Sentry has ignored these
        // since it grew `isPageLeaveAbortNoise`; the user had no such luck.
        //
        // If the departure does not take - an iOS PWA hands the bank redirect to
        // Safari and carries on polling in a live page - `leave-page` clears the
        // flag the next time the page becomes visible, and this speaks up again.
        if (isLeavingPage()) {
            return;
        }

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
