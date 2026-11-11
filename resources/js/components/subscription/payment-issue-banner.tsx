import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { leavePage } from '@/lib/leave-page';
import { billing } from '@/routes/settings';
import type { SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';

/**
 * Warns a user whose subscription payment failed, on every authenticated page.
 *
 * Stripe retries the charge 8 times over 3 weeks and then cancels, but
 * `Cashier::keepPastDueSubscriptionsActive()` keeps the account fully working
 * meanwhile, so nothing else in the app signals the failure.
 *
 * A payment the bank holds for 3D Secure (`requires_action`) is a different
 * ask: no retry gets it through, only the customer confirming it, so the
 * banner says that and its action opens Cashier's payment page instead. It is deliberately
 * not dismissible: a dismissed warning is a silent one, which is the bug this
 * replaces (a toast published before the app mounted, so a cold page load never
 * showed it).
 *
 * The action leaves the SPA on purpose — the billing portal route redirects to
 * Stripe, and the payment page is not an Inertia page.
 *
 * The billing page shows its own payment-failure alert, which also offers to
 * cancel, so the banner stays off there rather than say the same thing twice.
 */
export function SubscriptionPaymentIssueBanner() {
    const {
        url,
        props: { subscriptionPaymentIssue },
    } = usePage<SharedData>();

    if (!subscriptionPaymentIssue || url.split('?')[0] === billing.url()) {
        return null;
    }

    const needsConfirmation =
        subscriptionPaymentIssue.status === 'requires_action';

    return (
        <div className="mx-auto w-full max-w-page px-5 pb-4 sm:px-6">
            <Alert
                variant="destructive"
                className="border-destructive/30 bg-destructive/10"
            >
                <TriangleAlertIcon />
                <AlertTitle className="line-clamp-none">
                    {needsConfirmation
                        ? __(
                              'Your bank needs you to confirm your subscription payment.',
                          )
                        : __('We could not collect your subscription payment.')}
                </AlertTitle>
                <AlertDescription>
                    <p>
                        {needsConfirmation
                            ? __(
                                  'The payment does not go through until you approve it. You keep access for now, but the subscription will be canceled if it stays unconfirmed.',
                              )
                            : __(
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
                        {needsConfirmation
                            ? __('Confirm payment')
                            : __('Update payment')}
                    </Button>
                </AlertDescription>
            </Alert>
        </div>
    );
}
