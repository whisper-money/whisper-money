import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UpgradeDialog, type UpsellSource } from './upgrade-dialog';

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

const CTA = /Start Standard — free for/;

function renderDialog(source: UpsellSource = 'connections') {
    return render(
        <UpgradeDialog open onOpenChange={vi.fn()} source={source} />,
    );
}

describe('UpgradeDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.defaultPlan.current = 'yearly';
        mocks.latam.current = false;
    });

    it.each([
        [
            'ai_categorization',
            'Let the AI sort your movements',
            'Standard files every new movement for you and writes the rules that keep doing it. It switches on with your plan — no extra step.',
        ],
        [
            'connections',
            'Connect your bank and stop typing movements',
            'Standard keeps your accounts in sync, so new movements arrive on their own every day.',
        ],
        [
            'accounts',
            'Connect your bank and stop typing movements',
            'Standard keeps your accounts in sync, so new movements arrive on their own every day.',
        ],
        [
            'api',
            'Build on your data with Standard',
            'Create API tokens for your own scripts and integrations.',
        ],
        [
            'mcp',
            'Build on your data with Standard',
            'Create tokens to connect AI assistants to your data.',
        ],
    ] as const)('writes the %s copy itself', (source, title, description) => {
        renderDialog(source);

        expect(screen.getByText(title)).toBeInTheDocument();
        expect(screen.getByText(description)).toBeInTheDocument();
        expect(
            screen.getByText('And the rest of Standard'),
        ).toBeInTheDocument();
    });

    it('names the trial on the button, never another plan name', () => {
        renderDialog();

        expect(
            screen.getByRole('link', {
                name: 'Start Standard — free for 14 days',
            }),
        ).toBeInTheDocument();
        expect(screen.queryByText(/\bPro\b/)).toBeNull();
    });

    it('says what leaves the account only where the checkout grants the AI consent', () => {
        renderDialog('ai_categorization');

        expect(
            screen.getByText('What leaves your account'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Merchant names and amounts, one line at a time/),
        ).toBeInTheDocument();
    });

    it('keeps the AI disclosure when a caller adds its own rows', () => {
        render(
            <UpgradeDialog
                open
                onOpenChange={vi.fn()}
                source="ai_categorization"
            >
                <p>Custom preview</p>
            </UpgradeDialog>,
        );

        expect(screen.getByText('Custom preview')).toBeInTheDocument();
        expect(
            screen.getByText('What leaves your account'),
        ).toBeInTheDocument();
    });

    it.each(['connections', 'accounts', 'api', 'mcp'] as const)(
        'carries no AI disclosure on the %s upsell',
        (source) => {
            renderDialog(source);

            expect(screen.queryByText('What leaves your account')).toBeNull();
        },
    );

    it('attributes an API checkout to the API settings', () => {
        renderDialog('api');

        expect(
            screen.getByRole('link', { name: CTA }).getAttribute('href'),
        ).toContain('source=api');
    });

    it('promises no bank sync on the latam price', () => {
        mocks.latam.current = true;
        renderDialog('ai_categorization');

        expect(screen.queryByText('And the rest of Standard')).toBeNull();
        expect(
            screen.getByText('What leaves your account'),
        ).toBeInTheDocument();
    });

    it('links checkout with the selected plan and the upsell source', () => {
        renderDialog();

        // The CTA is the anchor itself — checkout is a server redirect into
        // Stripe, so it cannot be an Inertia visit.
        const link = screen.getByRole('link', { name: CTA });
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

        const link = screen.getByRole('link', { name: CTA });
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
        fireEvent.click(screen.getByRole('link', { name: CTA }));

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

        fireEvent.click(screen.getByRole('link', { name: CTA }));

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

        const link = screen.getByRole('link', { name: CTA });
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
            screen.getByRole('link', { name: CTA }).getAttribute('href'),
        ).not.toContain('tier=');
    });

    it('says nothing about tiers to a user only offered the standard price', () => {
        renderDialog();

        expect(screen.queryByTestId('price-tier-note')).toBeNull();
    });
});
