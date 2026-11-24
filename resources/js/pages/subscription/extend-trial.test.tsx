import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ExtendTrial from './extend-trial';

const mocks = vi.hoisted(() => ({
    routerPost: vi.fn(),
    visit: vi.fn(),
    captureEvent: vi.fn(),
    toastSuccess: vi.fn(),
    props: {} as Record<string, unknown>,
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));
vi.mock('sonner', () => ({
    toast: { success: mocks.toastSuccess, error: vi.fn() },
}));
vi.mock('@/layouts/subscription-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { post: mocks.routerPost, visit: mocks.visit },
    usePage: () => ({ props: mocks.props }),
}));

const EXTENSION = {
    days: 14,
    trialEndsAt: '2026-12-11T23:59:59+00:00',
    plan: { price: 8.99, billing_period: 'month' },
};

function renderPage(props: Record<string, unknown>) {
    mocks.props = {
        auth: { user: { id: 'user-1' }, hasProPlan: true },
        pricing,
        locale: 'en',
        errors: {},
        canKeepPlan: true,
        ...props,
    };

    render(<ExtendTrial />);
}

/** Runs the success callback of the last POST with the page it redirects to. */
function succeedLastPost(flash: string) {
    const options = mocks.routerPost.mock.lastCall?.[2];

    options.onSuccess({ props: { flash: { success: flash } } });
}

describe('ExtendTrial', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('states the new trial end and the first charge before anything changes', () => {
        renderPage({ extension: EXTENSION });

        expect(
            screen.getByRole('heading', { name: '14 more days of free trial' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Your trial ends on Dec 11, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('First charge on Dec 11, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('€8.99 a month, unless you cancel before then.'),
        ).toBeInTheDocument();
        expect(mocks.routerPost).not.toHaveBeenCalled();
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'trial_extension_page_viewed',
            { source: 'email', available: true },
        );
    });

    it('extends the trial on confirm and reports it', () => {
        renderPage({ extension: EXTENSION });

        fireEvent.click(screen.getByTestId('extend-trial'));

        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/settings/billing/extend-trial',
            {},
            expect.any(Object),
        );
        expect(mocks.captureEvent).not.toHaveBeenCalledWith(
            'trial_extended',
            expect.anything(),
        );

        succeedLastPost('Done: your trial now ends on December 11, 2026.');

        expect(mocks.captureEvent).toHaveBeenCalledWith('trial_extended', {
            source: 'email',
        });
        expect(mocks.toastSuccess).toHaveBeenCalledWith(
            'Done: your trial now ends on December 11, 2026.',
        );
    });

    it('leaves the price out when it is unknown', () => {
        renderPage({ extension: { ...EXTENSION, plan: null } });

        expect(
            screen.getByText('Unless you cancel before then.'),
        ).toBeInTheDocument();
    });

    it('says when the offer is gone and still lets a cancelled plan be kept', () => {
        renderPage({
            extension: null,
            errors: {
                trial_extension:
                    'This offer is no longer available on your plan.',
            },
        });

        expect(
            screen.getByRole('heading', {
                name: 'This offer is no longer available',
            }),
        ).toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This offer is no longer available on your plan.',
        );
        expect(screen.queryByTestId('extend-trial')).not.toBeInTheDocument();
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'trial_extension_page_viewed',
            { source: 'email', available: false },
        );

        fireEvent.click(screen.getByTestId('keep-plan'));

        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/settings/billing/resume',
            {},
            expect.any(Object),
        );
    });

    it('only points to the billing page when there is no cancellation to undo', () => {
        renderPage({ extension: null, canKeepPlan: false });

        expect(screen.queryByTestId('keep-plan')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'See my plan' }));

        expect(mocks.visit).toHaveBeenCalledWith('/settings/billing');
    });
});
