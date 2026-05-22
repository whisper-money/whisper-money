import type { SubscriptionPaymentIssueNotification } from '@/types';
import { __ } from '@/utils/i18n';
import { toast } from 'sonner';

const notifiedSubscriptionPaymentIssueKeys = new Set<string>();

export function resetSubscriptionPaymentIssueToastNotifications(): void {
    notifiedSubscriptionPaymentIssueKeys.clear();
}

export function showSubscriptionPaymentIssueToast(
    issue: SubscriptionPaymentIssueNotification | null | undefined,
    notifiedKeys: Set<string> = notifiedSubscriptionPaymentIssueKeys,
): boolean {
    if (!issue) {
        return false;
    }

    const toastKey = `${issue.status}:${issue.action_url}`;

    if (notifiedKeys.has(toastKey)) {
        return false;
    }

    notifiedKeys.add(toastKey);

    toast.warning(__('We could not collect your subscription payment.'), {
        description: __(
            'We will retry up to 4 times during the week. You keep access for now, but the subscription will be canceled if all retries fail.',
        ),
        duration: Infinity,
        action: {
            label: __('Update payment'),
            onClick: () => {
                window.location.href = issue.action_url;
            },
        },
    });

    return true;
}
