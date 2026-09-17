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

/** The same two plans, with the trial `SUBSCRIPTION_PAY_NOW` decides applied. */
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

describe('SubscriptionOffer', () => {
    it('promises the money back when the plan charges today', () => {
        mocks.pricing.current = pricingWithTrial(0, 0);

        render(<SubscriptionOffer selectedPlan="yearly" onSelect={vi.fn()} />);

        expect(
            screen.getByText('3 days to change your mind'),
        ).toBeInTheDocument();
    });

    /**
     * Nothing has been charged during a trial, so there is nothing to give
     * back. Shown anyway it sits next to "free for 15 days" and answers the
     * same question twice, in two different ways.
     */
    it('says nothing about refunds when the plan starts a trial', () => {
        mocks.pricing.current = pricingWithTrial(7, 15);

        render(<SubscriptionOffer selectedPlan="yearly" onSelect={vi.fn()} />);

        expect(screen.queryByText(/days to change your mind/)).toBeNull();
    });

    /** It follows the plan in hand, not whichever one happens to be cheapest. */
    it('follows the selected plan when only one of them has a trial', () => {
        mocks.pricing.current = pricingWithTrial(0, 15);

        const { rerender } = render(
            <SubscriptionOffer selectedPlan="monthly" onSelect={vi.fn()} />,
        );

        expect(
            screen.getByText('3 days to change your mind'),
        ).toBeInTheDocument();

        rerender(
            <SubscriptionOffer selectedPlan="yearly" onSelect={vi.fn()} />,
        );

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
 * The button names the charge and the line under it names the commitment, so
 * they answer the same question and cannot be allowed to disagree: a trial that
 * takes no money today must not sit under a button promising a sum today.
 */
describe('useOffer', () => {
    it('puts the amount on the button when it is charged today', () => {
        mocks.pricing.current = pricingWithTrial(0, 0);

        render(<Offer />);

        expect(screen.getByRole('link')).toHaveTextContent(/53[.,]94/);
        expect(
            screen.getByText(
                'Charged today. Full refund from Settings within 3 days.',
            ),
        ).toBeInTheDocument();
    });

    it('names the trial instead when nothing is charged today', () => {
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
