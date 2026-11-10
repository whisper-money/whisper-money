import type {
    BankConnectionIssue,
    TrialEndingBanner as Banner,
    SubscriptionPaymentIssueNotification,
} from '@/types';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TrialEndingBanner } from './trial-ending-banner';

const mocks = vi.hoisted(() => ({
    post: vi.fn(() => Promise.resolve()),
    routerPost: vi.fn(),
    captureEvent: vi.fn(),
    banner: null as Banner | null,
    paymentIssue: null as SubscriptionPaymentIssueNotification | null,
    bankIssues: [] as BankConnectionIssue[],
    showSecondOffer: false,
    url: '/dashboard',
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: mocks.url,
        props: {
            trialEndingBanner: mocks.banner,
            subscriptionPaymentIssue: mocks.paymentIssue,
            bankConnectionIssues: mocks.bankIssues,
            showSecondOffer: mocks.showSecondOffer,
        },
    }),
    router: { post: mocks.routerPost },
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

vi.mock('axios', () => ({ default: { post: mocks.post } }));
vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));
vi.mock('@/routes/settings', () => ({
    billing: { url: () => '/settings/billing' },
}));
vi.mock('@/routes/settings/billing', () => ({
    resume: { url: () => '/settings/billing/resume' },
}));
vi.mock('@/routes/trial-banner', () => ({
    dismiss: { url: () => '/trial-banner/dismiss' },
}));
vi.mock('@/utils/i18n', () => ({
    __: (message: string, replacements: Record<string, string> = {}) =>
        Object.entries(replacements).reduce(
            (text, [key, value]) => text.replace(`:${key}`, value),
            message,
        ),
}));

function banner(overrides: Partial<Banner> = {}): Banner {
    return {
        daysLeft: 7,
        threshold: 7,
        trialEndsOn: '2026-11-17',
        dismissalKey: '2026-11-17:7',
        canceled: false,
        charge: { amount: 3999, currency: 'EUR', period: 'year' },
        stats: { synced: 42, categorizedPercent: 87 },
        ...overrides,
    };
}

describe('TrialEndingBanner', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.banner = null;
        mocks.paymentIssue = null;
        mocks.bankIssues = [];
        mocks.showSecondOffer = false;
        mocks.url = '/dashboard';
    });

    it('renders nothing without a banner from the server', () => {
        const { container } = render(<TrialEndingBanner />);

        expect(container).toBeEmptyDOMElement();
    });

    it.each([
        [7, 7, 'Your free trial ends in 7 days'],
        [3, 3, 'Your free trial ends in 3 days'],
        [1, 1, 'Your free trial ends tomorrow'],
        [0, 1, 'Your free trial ends today'],
    ] as const)(
        'names the days left and the first charge (%i days)',
        (daysLeft, threshold, ends) => {
            mocks.banner = banner({ daysLeft, threshold });

            render(<TrialEndingBanner />);

            expect(
                screen.getByText(
                    `${ends} — €39.99 for the year on Nov 17, 2026`,
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByText(
                    'So far it synced 42 movements and sorted 87% of them. Nothing to do to keep it; you can change or cancel the plan any time before.',
                ),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: 'Manage plan' }),
            ).toHaveAttribute('href', '/settings/billing');
        },
    );

    it('quotes a monthly charge per month', () => {
        mocks.banner = banner({
            charge: { amount: 399, currency: 'EUR', period: 'month' },
        });

        render(<TrialEndingBanner />);

        expect(
            screen.getByText(
                'Your free trial ends in 7 days — €3.99 for the month on Nov 17, 2026',
            ),
        ).toBeInTheDocument();
    });

    it('names the date alone when the charge is unknown', () => {
        mocks.banner = banner({ daysLeft: 3, threshold: 3, charge: null });

        render(<TrialEndingBanner />);

        expect(
            screen.getByText(
                'Your free trial ends in 3 days — on Nov 17, 2026',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/€/)).not.toBeInTheDocument();
    });

    it('drops the stats it has nothing to say with', () => {
        mocks.banner = banner({ stats: { synced: 12, categorizedPercent: 0 } });
        const first = render(<TrialEndingBanner />);
        expect(
            screen.getByText(/^So far it synced 12 movements\. Nothing to do/),
        ).toBeInTheDocument();
        first.unmount();

        mocks.banner = banner({ stats: null });
        render(<TrialEndingBanner />);
        expect(
            screen.getByText(
                'Nothing to do to keep it; you can change or cancel the plan any time before.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/So far/)).not.toBeInTheDocument();
    });

    it('gives a trial set to cancel no charge line, only the way to keep the plan', () => {
        mocks.banner = banner({
            daysLeft: 3,
            threshold: 3,
            canceled: true,
            charge: null,
            stats: null,
        });

        render(<TrialEndingBanner />);

        expect(
            screen.getByText('Your free trial ends in 3 days'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                "Standard stops on Nov 17, 2026 and you won't be charged. Your movements stay yours.",
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/€/)).not.toBeInTheDocument();
        expect(screen.queryByText(/So far/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Keep my plan' }));

        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/settings/billing/resume',
            {},
            expect.any(Object),
        );
    });

    it('closes on dismiss and tells the server, which keeps it closed', () => {
        mocks.banner = banner();

        const { container } = render(<TrialEndingBanner />);
        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));

        expect(container).toBeEmptyDOMElement();
        expect(mocks.post).toHaveBeenCalledWith('/trial-banner/dismiss', {
            dismissal_key: '2026-11-17:7',
        });
    });

    it.each([
        [
            'a payment issue',
            () => {
                mocks.paymentIssue = {
                    status: 'past_due',
                    action_url: '/settings/billing/portal',
                };
            },
        ],
        [
            'a bank connection issue',
            () => {
                mocks.bankIssues = [
                    {
                        id: 'conn-1',
                        aspsp_name: 'Santander',
                        provider: 'enablebanking',
                        reason: 'expired',
                        reconnect_url:
                            '/open-banking/connections/conn-1/reconnect',
                    },
                ];
            },
        ],
        [
            'the second offer',
            () => {
                mocks.showSecondOffer = true;
            },
        ],
    ])(
        'stands aside for %s, so the shell shows one banner at most',
        (_, outrank) => {
            mocks.banner = banner();
            outrank();

            const { container } = render(<TrialEndingBanner />);

            expect(container).toBeEmptyDOMElement();
        },
    );

    it('stays off the billing page, which shows the trial itself', () => {
        mocks.banner = banner();
        mocks.url = '/settings/billing?tab=plan';

        const { container } = render(<TrialEndingBanner />);

        expect(container).toBeEmptyDOMElement();
    });
});
