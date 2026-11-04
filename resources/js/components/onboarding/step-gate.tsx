import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { planTerms } from '@/components/subscription/plan-picker';
import {
    SubscriptionOffer,
    useOffer,
    type OfferSource,
} from '@/components/subscription/subscription-offer';
import { useLocale } from '@/hooks/use-locale';
import { captureEvent, registerProperties } from '@/lib/posthog';
import { type SharedData } from '@/types';
import { type PricingConfig } from '@/types/pricing';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { Check, RotateCcw, Sparkles } from 'lucide-react';
import { useEffect } from 'react';

/**
 * Which paid thing the user just reached for. The screen is the same for all
 * three — it sells the same plan at the same price — but it argues for the
 * thing they asked for rather than for the plan in the abstract.
 *
 * `broker` is `bank` with the right noun in it. A pension or a crypto
 * portfolio is not a bank, but it is kept in sync the same way and costs the
 * same to keep, and `OpenBankingConnectController` gates it identically — so
 * it gets the offer rather than the 402 the endpoint would hand back.
 */
type GateKind = 'bank' | 'broker' | 'ai';

/**
 * The arm of the gate-copy experiment this user is in, handed down by the
 * onboarding page. Mirrors App\Features\OnboardingGateCopy.
 *
 * `trial_*` frames the plan as the free trial it starts with — the price moves
 * to the fine print — and preselects the plan in its name. Same plans, same
 * trial, same checkout as `control`.
 */
export type GateCopyArm = 'control' | 'trial_yearly' | 'trial_monthly';

const TRIAL_ARM_PLAN: Record<Exclude<GateCopyArm, 'control'>, string> = {
    trial_yearly: 'yearly',
    trial_monthly: 'monthly',
};

interface StepGateProps {
    kind: GateKind;
    /** How many movements the AI would have to read. Only the AI gate uses it. */
    transactionCount?: number;
    /** Carries on without paying: by hand for the bank, by hand for the sorting. */
    onDecline: () => void;
}

/**
 * The plan, asked for at the moment it is needed rather than at the end.
 *
 * Both paid features cost us money per user from the moment they are switched
 * on, so neither can be handed out and billed for later. The gate is what turns
 * that into something a user can act on: it comes up when they reach for the
 * feature, it says what the feature costs us, and it leaves the way past it
 * open — everything the wizard has done so far is free and stays free.
 *
 * The AI disclosure row is not decoration and is not negotiable. Grouping the
 * consent with the purchase is only honest if the purchase screen says, in a
 * row nobody can miss, what leaves the account and what does not. A consent
 * nobody read is not a consent.
 */
export function StepGate({
    kind,
    transactionCount = 0,
    onDecline,
}: StepGateProps) {
    const isAi = kind === 'ai';
    // Per kind, not bank-or-not: a broker checkout that reported itself as a
    // bank one came back to the bank picker, in front of a country list the
    // user never asked for.
    const source: OfferSource = {
        bank: 'onboarding_bank',
        broker: 'onboarding_broker',
        ai: 'onboarding_ai',
    }[kind] as OfferSource;
    const { gateCopyArm = null, pricing } = usePage<
        SharedData & { gateCopyArm?: GateCopyArm | null }
    >().props;
    const arm = isAi ? null : liveArm(gateCopyArm, pricing);
    const trialPlan = arm && arm !== 'control' ? TRIAL_ARM_PLAN[arm] : null;
    // Every event this screen sends names the arm, so the funnel splits by it
    // without a join; outside the experiment there is nothing to name.
    const armProps = arm ? { arm } : {};
    const { selectedPlan, setSelectedPlan, hasPlans, button, terms } = useOffer(
        source,
        {
            initialPlan: trialPlan ?? undefined,
            buttonText: trialPlan ? __('Start free trial') : undefined,
            onCheckout: (plan) =>
                captureEvent('onboarding_gate_checkout_started', {
                    gate: kind,
                    plan,
                    ...armProps,
                }),
        },
    );
    const locale = useLocale();

    useEffect(() => {
        // Registered here, where the user is exposed to the arm, rather than
        // where it is assigned: the bank connection and the trial converting
        // happen after a trip to Stripe, and still have to split by it.
        if (arm) {
            registerProperties({ onboarding_gate_arm: arm });
        }
        captureEvent('onboarding_gate_viewed', {
            gate: kind,
            ...(arm ? { arm } : {}),
        });
    }, [kind, arm]);

    // Nothing configured to sell: the gate would be a dead end rather than a
    // choice, so it steps aside and the free path stays open.
    useEffect(() => {
        if (!hasPlans) {
            onDecline();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [hasPlans]);

    if (!hasPlans) {
        return null;
    }

    const decline = () => {
        captureEvent('onboarding_gate_declined', { gate: kind, ...armProps });
        onDecline();
    };

    const plan = pricing.plans[selectedPlan];

    return (
        <StepScreen
            title={
                trialPlan
                    ? trialTitle(kind, plan?.trial_days ?? 0)
                    : connectTitle(kind)
            }
            description={
                trialPlan
                    ? __(
                          'Your movements arrive on their own, every day. Everything you’ve done so far stays free.',
                      )
                    : isAi
                      ? __(
                            'It reads all :count movements and writes the rules that file them. Every run costs us money, so it sits on the paid plan.',
                            { count: transactionCount },
                        )
                      : __(
                            'Every account we keep in sync costs us money with the provider behind it. Everything you’ve done so far stays free.',
                        )
            }
            footer={
                <>
                    {button}
                    <StepButton
                        text={
                            isAi
                                ? __('I’ll sort them myself')
                                : __('Not now — I’ll add accounts by hand')
                        }
                        variant="ghost"
                        onClick={decline}
                        data-testid="decline-gate"
                    />
                    {trialPlan ? (
                        <StepNote>
                            {planTerms(plan, pricing.currency, locale)}
                        </StepNote>
                    ) : (
                        terms
                    )}
                </>
            }
        >
            <SubscriptionOffer
                selectedPlan={selectedPlan}
                onSelect={setSelectedPlan}
            />

            <StepList>
                <AiDisclosure kind={kind} />
                {/* The free way past this gate is only an answer on the AI
                    one. Telling someone who reached for a broker that they can
                    "keep sorting by hand" answers a question they never asked. */}
                <StepRow
                    icon={isAi ? RotateCcw : Check}
                    title={
                        isAi
                            ? __('Or keep sorting by hand')
                            : __('And the rest of Standard')
                    }
                    description={
                        isAi
                            ? __('Free, unlimited, and your rules still work')
                            : __('Bank sync, unlimited accounts, daily updates')
                    }
                />
            </StepList>
        </StepScreen>
    );
}

/**
 * What the AI sees, on the screen that charges for it.
 *
 * The bank gate switches the sorting on as part of the plan, so it says so
 * outright; the AI gate was asked for the sorting, so it goes straight to what
 * leaves the account. Both name the three things that never do, because the
 * promise on the website is that the data is never sold or shared with
 * advertisers, and this is where part of it does reach an AI provider.
 */
function AiDisclosure({ kind }: { kind: GateKind }) {
    return (
        <StepRow
            icon={Sparkles}
            title={
                kind === 'ai'
                    ? __('What leaves your account')
                    : __('Automatic sorting comes on with it')
            }
            description={
                kind === 'ai'
                    ? __(
                          'Merchant names and amounts, one line at a time — never your balance, your name or your account number. One tap in Settings turns it off.',
                      )
                    : __(
                          'Merchant names and amounts go to our AI provider so it can file them — never your balance, your name or your account number. One tap in Settings turns it off.',
                      )
            }
        />
    );
}

/**
 * The arm the gate is actually drawn in. The trial framing only says something
 * true while both plans start with a trial, so with `SUBSCRIPTION_PAY_NOW` on
 * the experiment is off for everyone — control included, or control would be
 * measured against two arms that look exactly like it.
 */
function liveArm(
    arm: GateCopyArm | null,
    pricing: PricingConfig,
): GateCopyArm | null {
    if (!arm) {
        return null;
    }

    const everyPlanHasTrial = Object.values(TRIAL_ARM_PLAN).every(
        (key) => (pricing.plans[key]?.trial_days ?? 0) > 0,
    );

    return everyPlanHasTrial ? arm : null;
}

/** The trial-framed headline: what they reached for, and that it starts free. */
function trialTitle(kind: GateKind, days: number): string {
    return kind === 'broker'
        ? __('Connect your account free for :days days', { days })
        : __('Connect your bank free for :days days', { days });
}

/** The thing the user reached for, named as they named it. */
function connectTitle(kind: GateKind): string {
    return {
        bank: __('Connecting a bank needs Standard'),
        broker: __('Connecting an account needs Standard'),
        ai: __('AI sorting needs Standard'),
    }[kind];
}
