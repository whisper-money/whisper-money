import { type PriceTierChoice } from '@/hooks/use-price-tier';
import { pricingFixture } from '@/lib/pricing-fixture';
import { type PricingConfig } from '@/types/pricing';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SubscriptionOffer, useOffer } from './subscription-offer';

const mocks = vi.hoisted(() => ({
    pricing: { current: null as PricingConfig | null },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'en', pricing: mocks.pricing.current },
    }),
}));

/** The same two plans, each with the trial given. */
function pricingWithTrial(monthly: number, yearly: number): PricingConfig {
    return {
        ...pricingFixture,
        plans: {
            monthly: { ...pricingFixture.plans.monthly, trial_days: monthly },
            yearly: { ...pricingFixture.plans.yearly, trial_days: yearly },
        },
    };
}

beforeEach(() => {
    mocks.pricing.current = pricingFixture;
});

/** The standard tier, quoting whatever plans the test set up. */
function standardTier(): PriceTierChoice {
    return {
        tier: 'standard',
        plans: mocks.pricing.current!.plans,
        canSwitch: false,
        setTier: vi.fn(),
    };
}

describe('SubscriptionOffer', () => {
    /**
     * Every plan opens on a trial, so nothing has been charged and there is
     * nothing to give back: no refund promise sits next to "free for 14 days".
     */
    it('lists the plans without a refund promise', () => {
        render(
            <SubscriptionOffer
                selectedPlan="yearly"
                onSelect={vi.fn()}
                tierChoice={standardTier()}
            />,
        );

        expect(screen.getByText(/53[.,]94/)).toBeInTheDocument();
        expect(screen.queryByText(/days to change your mind/)).toBeNull();
    });
});

/** The button and the terms, as every paid screen gets them from the hook. */
function Offer() {
    const { button, terms } = useOffer();

    return (
        <>
            {button}
            {terms}
        </>
    );
}

/**
 * The button names the trial and the line under it names the commitment, so
 * they answer the same question and cannot be allowed to disagree.
 */
describe('useOffer', () => {
    it('starts on the configured default plan', () => {
        mocks.pricing.current = {
            ...pricingWithTrial(7, 14),
            defaultPlan: 'monthly',
        };

        render(<Offer />);

        expect(screen.getByRole('link')).toHaveTextContent(
            'Start Standard — free for 7 days',
        );
        expect(screen.getByRole('link').getAttribute('href')).toMatch(
            /[?&]plan=monthly&preselected_plan=monthly/,
        );
    });

    it('names the trial on the button and in the terms', () => {
        mocks.pricing.current = pricingWithTrial(7, 15);

        render(<Offer />);

        expect(screen.getByRole('link')).toHaveTextContent(
            'Start Standard — free for 15 days',
        );
        expect(
            screen.getByText(
                'Free for 15 days. Cancel before then and you are not charged.',
            ),
        ).toBeInTheDocument();
    });
});
