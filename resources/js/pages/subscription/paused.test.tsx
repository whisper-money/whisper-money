import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Paused from './paused';

const mocks = vi.hoisted(() => ({
    routerPost: vi.fn(),
    visit: vi.fn(),
    captureEvent: vi.fn(),
    errors: {} as Record<string, string>,
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/layouts/subscription-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { post: mocks.routerPost, visit: mocks.visit },
    usePage: () => ({
        props: {
            auth: { user: { id: 'user-1' }, hasProPlan: false },
            pricing,
            locale: 'en',
            errors: mocks.errors,
            pause: {
                active: true,
                startsAt: '2026-11-01T10:00:00+00:00',
                resumesAt: '2026-12-25T09:00:00+00:00',
                plan: { price: 8.99, billing_period: 'month' },
            },
        },
    }),
}));

describe('Paused', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.errors = {};
    });

    it('says until when, and offers to resume instead of a checkout', () => {
        render(<Paused />);

        expect(
            screen.getByText('Your plan is paused until Dec 25, 2026'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Resuming now charges €8\.99 a month today/),
        ).toBeInTheDocument();
        expect(screen.queryByText(/free for/)).not.toBeInTheDocument();
        expect(mocks.captureEvent).toHaveBeenCalledWith('paywall_viewed', {
            gate: 'paused',
        });
    });

    it('resumes now', () => {
        render(<Paused />);

        fireEvent.click(screen.getByTestId('resume-paused-plan'));

        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/settings/billing/pause/resume',
            {},
            expect.anything(),
        );
    });

    it('leads to the billing page', () => {
        render(<Paused />);

        fireEvent.click(screen.getByText('See my plan'));

        expect(mocks.visit).toHaveBeenCalledWith('/settings/billing');
    });
});
