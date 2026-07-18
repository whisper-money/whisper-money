import { PricingConfig } from '@/types/pricing';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UpgradeDialog } from './upgrade-dialog';

const mocks = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));

const pricing: PricingConfig = {
    plans: {
        monthly: {
            name: 'Monthly',
            price: 3.99,
            original_price: null,
            stripe_lookup_key: 'monthly',
            billing_period: 'month',
            features: [],
        },
        yearly: {
            name: 'Annual',
            price: 23.88,
            original_price: 47.88,
            stripe_lookup_key: 'yearly',
            billing_period: 'year',
            features: [],
        },
    },
    defaultPlan: 'yearly',
    bestValuePlan: 'yearly',
    promo: { enabled: false, code: '', description: '', badge: '' },
    currency: 'EUR',
};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { pricing, locale: 'en' } }),
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
    beforeEach(() => vi.clearAllMocks());

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

        const link = screen
            .getByRole('button', { name: /Upgrade to Standard Plan/ })
            .closest('a');
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
    });

    it('captures a checkout-started event tagged with the source', () => {
        renderDialog();

        fireEvent.click(
            screen.getByRole('button', { name: /Upgrade to Standard Plan/ }),
        );

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'upgrade_checkout_started',
            { source: 'connections', plan: 'yearly' },
        );
    });
});
