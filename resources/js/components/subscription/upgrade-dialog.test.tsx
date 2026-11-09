import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UpgradeDialog } from './upgrade-dialog';

const mocks = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    defaultPlan: { current: 'yearly' },
    latam: { current: false },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            pricing: {
                ...pricing,
                defaultPlan: mocks.defaultPlan.current,
                ...(mocks.latam.current
                    ? {
                          latam: {
                              plans: {
                                  monthly: {
                                      ...pricing.plans.monthly,
                                      price: 3.99,
                                  },
                                  yearly: {
                                      ...pricing.plans.yearly,
                                      price: 23.88,
                                  },
                              },
                              timezones: null,
                          },
                          offeredTier: 'latam',
                      }
                    : {}),
            },
            auth: { user: { id: 'user-1' } },
            locale: 'en',
        },
    }),
}));

function renderDialog(
    source: 'connections' | 'ai_categorization' = 'connections',
) {
    return render(
        <UpgradeDialog
            open
            onOpenChange={vi.fn()}
            title="Bank connections are a paid feature"
            description="Subscribe to sync your bank."
            source={source}
        />,
    );
}

describe('UpgradeDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.defaultPlan.current = 'yearly';
        mocks.latam.current = false;
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
            screen.getByText(
                'Free for 7 days, then €8.99 a month. Cancel before then and you are not charged.',
            ),
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
                price_tier: 'standard',
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
                price_tier: 'standard',
            },
        );
    });

    it('opens a bank upsell on the standard price, with latam a click away', () => {
        mocks.latam.current = true;
        renderDialog('connections');

        expect(
            screen.getByText(
                'Free for 14 days, then €53.94 a year. Cancel before then and you are not charged.',
            ),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('switch-price-tier'));

        expect(
            screen.getByText(
                'Free for 14 days, then €23.88 a year. Cancel before then and you are not charged.',
            ),
        ).toBeInTheDocument();
    });

    it('quotes a user offered latam the latam price, and lets them pick standard', () => {
        mocks.latam.current = true;
        renderDialog('ai_categorization');

        expect(
            screen.getByText(
                'No bank connection · direct integrations included',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Free for 14 days, then €23.88 a year. Cancel before then and you are not charged.',
            ),
        ).toBeInTheDocument();

        const link = screen.getByRole('link', { name: /Start a plan/ });
        expect(link.getAttribute('href')).toContain('tier=latam');

        fireEvent.click(link);
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'upgrade_checkout_started',
            expect.objectContaining({ price_tier: 'latam' }),
        );

        fireEvent.click(screen.getByTestId('switch-price-tier'));

        expect(
            screen.getByText(
                'Free for 14 days, then €53.94 a year. Cancel before then and you are not charged.',
            ),
        ).toBeInTheDocument();
        expect(
            screen
                .getByRole('link', { name: /Start a plan/ })
                .getAttribute('href'),
        ).not.toContain('tier=');
    });

    it('says nothing about tiers to a user only offered the standard price', () => {
        renderDialog();

        expect(screen.queryByTestId('price-tier-note')).toBeNull();
    });
});
