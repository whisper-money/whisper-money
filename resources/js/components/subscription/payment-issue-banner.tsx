import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { leavePage } from '@/lib/leave-page';
import type { SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';

/**
 * Warns a user whose subscription payment failed, on every authenticated page.
 *
 * Stripe retries the charge for two weeks and then cancels, but
 * `Cashier::keepPastDueSubscriptionsActive()` keeps the account fully working
 * meanwhile, so nothing else in the app signals the failure. It is deliberately
 * not dismissible: a dismissed warning is a silent one, which is the bug this
 * replaces (a toast published before the app mounted, so a cold page load never
 * showed it).
 *
 * The action leaves the SPA on purpose — the billing portal route redirects to
 * Stripe, which Inertia cannot follow.
 */
export function SubscriptionPaymentIssueBanner() {
    const { subscriptionPaymentIssue } = usePage<SharedData>().props;

    if (!subscriptionPaymentIssue) {
        return null;
    }

    return (
        <div className="mx-auto w-full max-w-page px-5 pb-4 sm:px-6">
            <Alert
                variant="destructive"
                className="border-destructive/30 bg-destructive/10"
            >
                <TriangleAlertIcon />
                <AlertTitle className="line-clamp-none">
                    {__('We could not collect your subscription payment.')}
                </AlertTitle>
                <AlertDescription>
                    <p>
                        {__(
                            'We will keep retrying over the coming days. You keep access for now, but the subscription will be canceled if all retries fail.',
                        )}
                    </p>
                    <Button
                        size="sm"
                        variant="destructive"
                        className="mt-1"
                        onClick={() =>
                            leavePage(subscriptionPaymentIssue.action_url)
                        }
                    >
                        {__('Update payment')}
                    </Button>
                </AlertDescription>
            </Alert>
        </div>
    );
}
