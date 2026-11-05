import { StepButton } from '@/components/onboarding/step-button';
import { StepHighlight } from '@/components/onboarding/step-list';
import { StepNote } from '@/components/onboarding/step-screen';
import { PlanPicker } from '@/components/subscription/plan-picker';
import { useLocale } from '@/hooks/use-locale';
import { checkout } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { type Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useState } from 'react';

/**
 * Which screen an offer is being made on. It is only ever attribution — the
 * plans, the price and the refund window are the same wherever the user is
 * asked — but the two onboarding gates also carry the AI consent and a way back
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
 * The paid offer, as every screen that makes it renders it: the two plans, then
 * the money-back window in the one colour this interface spends.
 *
 * All five paid screens — the two onboarding gates, the soft paywall, the one a
 * former subscriber gets — show exactly this, so the price, the saving and the
 * terms cannot drift between the place a user is asked and the place they come
 * back to. `useOffer` hands the caller the selected plan and the button, which
 * is the other half that has to agree with it.
 *
 * The money-back window belongs to a plan that charges today. A plan with a
 * trial in front of it has taken no money, so there is nothing to give back,
 * and promising it next to "free for 7 days" is two answers to one question.
 */
export function SubscriptionOffer({
    selectedPlan,
    onSelect,
}: {
    selectedPlan: string;
    onSelect: (key: string) => void;
}) {
    const { pricing } = usePage<SharedData>().props;
    const chargedToday = pricing.plans[selectedPlan]?.trial_days === 0;

    return (
        <div className="flex flex-col gap-5">
            <PlanPicker
                plans={pricing.plans}
                currency={pricing.currency}
                selectedPlan={selectedPlan}
                onSelect={onSelect}
            />

            {chargedToday && <RefundPromise />}
        </div>
    );
}

/**
 * The money-back window. It says what it costs to use it (one tap), what comes
 * back (all of it), and what does not go away (the data already imported) —
 * because "refund" on its own reads as "and we delete everything".
 */
export function RefundPromise() {
    const { pricing } = usePage<SharedData>().props;

    return (
        <StepHighlight
            icon={RotateCcw}
            title={__(':days days to change your mind', {
                days: pricing.refundWindowDays,
            })}
        >
            {__(
                'One tap in Settings. Refunded in full, banks disconnected, imported data kept.',
            )}
        </StepHighlight>
    );
}

/**
 * The checkout URL every paid screen links to. `preselectedPlan` is the plan
 * the picker had selected when it was shown — the configured default, or the
 * plan an experiment arm starts on instead — so revenue can be split by what
 * the user was offered first, not only by what they bought.
 */
export function checkoutHref(
    plan: string,
    preselectedPlan: string,
    source?: string | null,
): string {
    return checkout.url({
        query: {
            plan,
            preselected_plan: preselectedPlan,
            ...(source ? { source } : {}),
        },
    });
}

interface OfferOptions {
    /** The plan to start on instead of the configured default. */
    initialPlan?: string;
    /** Replaces the label that names the charge, e.g. "Start free trial". */
    buttonText?: string;
    /**
     * Runs on the way out to checkout, with the plan being bought and the one
     * the picker had preselected.
     */
    onCheckout?: (plan: string, preselectedPlan: string) => void;
}

/**
 * The plan picker's state and the two controls that have to agree with it: a
 * button that names the amount it is about to charge, and the line under it
 * that says when.
 */
export function useOffer(
    source: OfferSource = null,
    { initialPlan, buttonText, onCheckout }: OfferOptions = {},
) {
    const { pricing } = usePage<SharedData>().props;
    const locale = useLocale();
    // Captured once, like the state it seeds: the plan the user was shown
    // first, whatever they switch to afterwards.
    const [preselectedPlan] = useState(initialPlan ?? pricing.defaultPlan);
    const [selectedPlan, setSelectedPlan] = useState(preselectedPlan);
    const plan: Plan | undefined = pricing.plans[selectedPlan];

    return {
        selectedPlan,
        setSelectedPlan,
        plan,
        /** Whether there is anything to sell at all — no plans, no screen. */
        hasPlans: plan !== undefined,
        button: (
            <StepButton
                text={
                    buttonText ?? checkoutLabel(plan, pricing.currency, locale)
                }
                href={checkoutHref(selectedPlan, preselectedPlan, source)}
                onClick={
                    onCheckout &&
                    (() => onCheckout(selectedPlan, preselectedPlan))
                }
                data-testid="start-plan"
            />
        ),
        terms: <CheckoutTerms plan={plan} />,
    };
}

/**
 * What the button charges, on the button. A checkout button that says only
 * "Continue" makes the user scroll back up to find out what they just agreed
 * to; this one is readable with the plan rows off screen.
 *
 * A plan with a trial in front of it charges nothing today, so it says what it
 * does instead of naming a sum that is not about to leave the account — the
 * terms underneath say the same thing, and the two cannot be allowed to
 * disagree on the one screen that asks for money.
 */
function checkoutLabel(
    plan: Plan | undefined,
    currency: string,
    locale: string,
): string {
    if (!plan) {
        return __('Start Standard');
    }

    if (plan.trial_days > 0) {
        return __('Start Standard — free for :days days', {
            days: plan.trial_days,
        });
    }

    return __('Start Standard — :total today', {
        total: formatCurrency(plan.price * 100, currency, locale),
    });
}

/**
 * The commitment under the button. A plan with a trial in front of it is not
 * charged today and has no refund window to promise, so it says so instead —
 * which of the two ships is `SUBSCRIPTION_PAY_NOW`, read here as the plan's own
 * `trial_days`.
 */
function CheckoutTerms({ plan }: { plan: Plan | undefined }) {
    const { pricing } = usePage<SharedData>().props;

    if (!plan) {
        return null;
    }

    if (plan.trial_days > 0) {
        return (
            <StepNote emphasis>
                {__(
                    'Free for :days days. Cancel before then and you are not charged.',
                    { days: plan.trial_days },
                )}
            </StepNote>
        );
    }

    return (
        <StepNote emphasis>
            {__('Charged today. Full refund from Settings within :days days.', {
                days: pricing.refundWindowDays,
            })}
        </StepNote>
    );
}
