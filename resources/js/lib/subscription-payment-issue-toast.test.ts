import { beforeEach, describe, expect, it, vi } from 'vitest';
import { showSubscriptionPaymentIssueToast } from './subscription-payment-issue-toast';

const mocks = vi.hoisted(() => ({
    warning: vi.fn(),
}));

vi.mock('sonner', () => ({
    toast: {
        warning: mocks.warning,
    },
}));

vi.mock('@/utils/i18n', () => ({
    __: (message: string) => message,
}));

describe('showSubscriptionPaymentIssueToast', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows a warning for past due subscription payments', () => {
        const shown = showSubscriptionPaymentIssueToast(
            {
                status: 'past_due',
                action_url: '/settings/billing/portal',
            },
            new Set<string>(),
        );

        expect(shown).toBe(true);
        expect(mocks.warning).toHaveBeenCalledWith(
            'We could not collect your subscription payment.',
            expect.objectContaining({
                description:
                    'We will retry up to 4 times during the week. You keep access for now, but the subscription will be canceled if all retries fail.',
                duration: Infinity,
                action: expect.objectContaining({
                    label: 'Update payment',
                }),
            }),
        );
    });

    it('does not show duplicate warnings for the same issue', () => {
        const notifiedKeys = new Set<string>();
        const issue = {
            status: 'past_due' as const,
            action_url: '/settings/billing/portal',
        };

        expect(showSubscriptionPaymentIssueToast(issue, notifiedKeys)).toBe(
            true,
        );
        expect(showSubscriptionPaymentIssueToast(issue, notifiedKeys)).toBe(
            false,
        );
        expect(mocks.warning).toHaveBeenCalledOnce();
    });
});
