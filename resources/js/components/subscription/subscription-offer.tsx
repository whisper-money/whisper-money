import { StepButton } from '@/components/onboarding/step-button';
import { StepNote } from '@/components/onboarding/step-screen';
import { PlanPicker } from '@/components/subscription/plan-picker';
import {
    type PriceTierChoice,
    usePriceTierChoice,
} from '@/hooks/use-price-tier';
import { type PriceTier } from '@/lib/price-tier';
import { checkout } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { type Plan } from '@/types/pricing';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Which screen an offer is being made on. It is only ever attribution — the
 * plans, the price and the trial are the same wherever the user is asked — but the two onboarding gates also carry the AI consent and a way back
 * into the wizard, and the server reads them off this.
 *
 * Mirrors the App\Enums\UpsellSource cases that appear on these screens.
 */
export type OfferSource =
    | 'onboarding_bank'
    | 'onboarding_broker'
    | 'onboarding_ai'
    | null;

/**
 * The paid offer, as every screen that makes it renders it: the two plans.
 *
 * All five paid screens — the two onboarding gates, the soft paywall, the one a
 * former subscriber gets — show exactly this, so the price, the saving and the
 * terms cannot drift between the place a user is asked and the place they come
 * back to. `useOffer` hands the caller the selected plan and the button, which
 * is the other half that has to agree with it.
 */
export function SubscriptionOffer({
    selectedPlan,
    onSelect,
    tierChoice,
}: {
    selectedPlan: string;
    onSelect: (key: string) => void;
    /** The tier `useOffer` is quoting, so the rows and the button agree. */
    tierChoice: PriceTierChoice;
}) {
    const { pricing } = usePage<SharedData>().props;

    return (
        <PlanPicker
            plans={tierChoice.plans}
            currency={pricing.currency}
            selectedPlan={selectedPlan}
            onSelect={onSelect}
            tierChoice={tierChoice}
        />
    );
}

/**
 * The checkout URL every paid screen links to. `preselectedPlan` is the plan
 * the picker had selected when it was shown — the configured default, or the
 * plan an experiment arm starts on instead — so revenue can be split by what
 * the user was offered first, not only by what they bought. The latam tier is
 * only asked for: the server decides whether this user may have it.
 */
export function checkoutHref(
    plan: string,
    preselectedPlan: string,
    source?: string | null,
    tier: PriceTier = 'standard',
): string {
    return checkout.url({
        query: {
            plan,
            preselected_plan: preselectedPlan,
            ...(source ? { source } : {}),
            ...(tier === 'latam' ? { tier } : {}),
        },
    });
}

interface OfferOptions {
    /** The plan to start on instead of the configured default. */
    initialPlan?: string;
    /** Replaces the label that names the charge, e.g. "Start free trial". */
    buttonText?: string;
    /**
     * Runs on the way out to checkout, with the plan being bought, the one the
     * picker had preselected and the tier it is quoted at.
     */
    onCheckout?: (
        plan: string,
        preselectedPlan: string,
        priceTier: PriceTier,
    ) => void;
    /**
     * The screen sells something only the standard price includes (a bank
     * connection), so the latam price is not offered on it.
     */
    requiresStandard?: boolean;
}

/**
 * The plan picker's state and the two controls that have to agree with it: a
 * button that names the amount it is about to charge, and the line under it
 * that says when.
 */
export function useOffer(
    source: OfferSource = null,
    {
        initialPlan,
        buttonText,
        onCheckout,
        requiresStandard = false,
    }: OfferOptions = {},
) {
    const { pricing } = usePage<SharedData>().props;
    const tierChoice = usePriceTierChoice({ requiresStandard });
    // Captured once, like the state it seeds: the plan the user was shown
    // first, whatever they switch to afterwards.
    const [preselectedPlan] = useState(initialPlan ?? pricing.defaultPlan);
    const [selectedPlan, setSelectedPlan] = useState(preselectedPlan);
    const plan: Plan | undefined = tierChoice.plans[selectedPlan];

    return {
        selectedPlan,
        setSelectedPlan,
        plan,
        tierChoice,
        /** Whether there is anything to sell at all — no plans, no screen. */
        hasPlans: plan !== undefined,
        button: (
            <StepButton
                text={buttonText ?? checkoutLabel(plan)}
                href={checkoutHref(
                    selectedPlan,
                    preselectedPlan,
                    source,
                    tierChoice.tier,
                )}
                onClick={
                    onCheckout &&
                    (() =>
                        onCheckout(
                            selectedPlan,
                            preselectedPlan,
                            tierChoice.tier,
                        ))
                }
                data-testid="start-plan"
            />
        ),
        terms: <CheckoutTerms plan={plan} />,
    };
}

/**
 * What the button starts, on the button. Every plan opens on a free trial, so
 * it names the trial rather than a sum that is not about to leave the account —
 * the terms underneath say the same thing, and the two cannot be allowed to
 * disagree on the one screen that asks for money.
 */
function checkoutLabel(plan: Plan | undefined): string {
    if (!plan) {
        return __('Start Standard');
    }

    return __('Start Standard — free for :days days', {
        days: plan.trial_days,
    });
}

/** The commitment under the button: the trial, and that nothing is charged in it. */
function CheckoutTerms({ plan }: { plan: Plan | undefined }) {
    if (!plan) {
        return null;
    }

    return (
        <StepNote emphasis>
            {__(
                'Free for :days days. Cancel before then and you are not charged.',
                { days: plan.trial_days },
            )}
        </StepNote>
    );
}
