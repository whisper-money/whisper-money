import type { SubscriptionPaymentIssueNotification } from '@/types';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SubscriptionPaymentIssueBanner } from './payment-issue-banner';

const mocks = vi.hoisted(() => ({
    leavePage: vi.fn(),
    issue: null as SubscriptionPaymentIssueNotification | null,
    url: '/dashboard',
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: mocks.url,
        props: { subscriptionPaymentIssue: mocks.issue },
    }),
}));

vi.mock('@/lib/leave-page', () => ({
    leavePage: mocks.leavePage,
}));

vi.mock('@/utils/i18n', () => ({
    __: (message: string) => message,
}));

describe('SubscriptionPaymentIssueBanner', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.issue = null;
        mocks.url = '/dashboard';
    });

    it('stays off the billing page, which has its own payment-failure alert', () => {
        mocks.issue = {
            status: 'past_due',
            action_url: '/settings/billing/portal',
        };
        mocks.url = '/settings/billing';

        const { container } = render(<SubscriptionPaymentIssueBanner />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when there is no payment issue', () => {
        const { container } = render(<SubscriptionPaymentIssueBanner />);

        expect(container).toBeEmptyDOMElement();
    });

    it('warns about the failed payment without promising a retry count', () => {
        mocks.issue = {
            status: 'past_due',
            action_url: '/settings/billing/portal',
        };

        render(<SubscriptionPaymentIssueBanner />);

        expect(
            screen.getByText('We could not collect your subscription payment.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'We will keep retrying over the coming days. You keep access for now, but the subscription will be canceled if all retries fail.',
            ),
        ).toBeInTheDocument();
    });

    it('leaves the SPA for the billing portal, which redirects to Stripe', () => {
        mocks.issue = {
            status: 'past_due',
            action_url: '/settings/billing/portal',
        };

        render(<SubscriptionPaymentIssueBanner />);
        fireEvent.click(screen.getByRole('button', { name: 'Update payment' }));

        expect(mocks.leavePage).toHaveBeenCalledWith(
            '/settings/billing/portal',
        );
    });
});

describe('SubscriptionPaymentIssueBanner when the bank asks for a confirmation', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.url = '/dashboard';
        mocks.issue = {
            status: 'requires_action',
            action_url: '/stripe/payment/pi_123?redirect=%2Fsettings%2Fbilling',
        };
    });

    it('asks to confirm the payment rather than to update the card', () => {
        render(<SubscriptionPaymentIssueBanner />);

        expect(
            screen.getByText(
                'Your bank needs you to confirm your subscription payment.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Update payment' }),
        ).not.toBeInTheDocument();
    });

    it("leaves the SPA for Cashier's payment page", () => {
        render(<SubscriptionPaymentIssueBanner />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm payment' }),
        );

        expect(mocks.leavePage).toHaveBeenCalledWith(
            '/stripe/payment/pi_123?redirect=%2Fsettings%2Fbilling',
        );
    });

    it('stays off the billing page, whose own alert offers the confirmation', () => {
        mocks.url = '/settings/billing';

        const { container } = render(<SubscriptionPaymentIssueBanner />);

        expect(container).toBeEmptyDOMElement();
    });
});
