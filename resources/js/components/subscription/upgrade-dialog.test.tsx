import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UpgradeDialog } from './upgrade-dialog';

const mocks = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    defaultPlan: { current: 'yearly' },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            pricing: { ...pricing, defaultPlan: mocks.defaultPlan.current },
            locale: 'en',
        },
    }),
}));

function renderDialog() {
    return render(
        <UpgradeDialog
            open
            onOpenChange={vi.fn()}
            title="Bank connections are a paid feature"
            description="Subscribe to sync your bank."
            source="connections"
        />,
    );
}

describe('UpgradeDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.defaultPlan.current = 'yearly';
    });

    it('renders the per-feature title and description', () => {
        renderDialog();

        expect(
            screen.getByText('Bank connections are a paid feature'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Subscribe to sync your bank.'),
        ).toBeInTheDocument();
    });

    it('links checkout with the selected plan and the upsell source', () => {
        renderDialog();

        // The CTA is the anchor itself — checkout is a server redirect into
        // Stripe, so it cannot be an Inertia visit.
        const link = screen.getByRole('link', { name: /Start a plan/ });
        // Default plan is the configured default (yearly), and the source rides
        // along so the subscription can be attributed to this upsell point.
        expect(link).toHaveAttribute(
            'href',
            expect.stringContaining('plan=yearly'),
        );
        expect(link).toHaveAttribute(
            'href',
            expect.stringContaining('source=connections'),
        );
        expect(link).toHaveAttribute(
            'href',
            expect.stringContaining('preselected_plan=yearly'),
        );
    });

    it('preselects monthly when it is the configured default', () => {
        mocks.defaultPlan.current = 'monthly';
        renderDialog();

        const link = screen.getByRole('link', { name: /Start a plan/ });
        expect(link).toHaveAttribute(
            'href',
            expect.stringMatching(/[?&]plan=monthly/),
        );
        expect(link).toHaveAttribute(
            'href',
            expect.stringContaining('preselected_plan=monthly'),
        );
        expect(
            screen.getByText('€8.99 charged today. Cancel any time.'),
        ).toBeInTheDocument();
    });

    it('keeps the preselected plan when the user switches plans', () => {
        mocks.defaultPlan.current = 'monthly';
        renderDialog();

        fireEvent.click(screen.getByText('Yearly'));
        fireEvent.click(screen.getByRole('link', { name: /Start a plan/ }));

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'upgrade_checkout_started',
            {
                source: 'connections',
                plan: 'yearly',
                preselected_plan: 'monthly',
            },
        );
    });

    it('captures a checkout-started event tagged with the source', () => {
        renderDialog();

        fireEvent.click(screen.getByRole('link', { name: /Start a plan/ }));

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'upgrade_checkout_started',
            {
                source: 'connections',
                plan: 'yearly',
                preselected_plan: 'yearly',
            },
        );
    });
});
