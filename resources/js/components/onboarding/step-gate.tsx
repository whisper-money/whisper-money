import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import {
    SubscriptionOffer,
    useOffer,
    type OfferSource,
} from '@/components/subscription/subscription-offer';
import { captureEvent } from '@/lib/posthog';
import { __ } from '@/utils/i18n';
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
    const source: OfferSource = isAi ? 'onboarding_ai' : 'onboarding_bank';
    const { selectedPlan, setSelectedPlan, hasPlans, button, terms } =
        useOffer(source);

    useEffect(() => {
        captureEvent('onboarding_gate_viewed', { gate: kind });
    }, [kind]);

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
        captureEvent('onboarding_gate_declined', { gate: kind });
        onDecline();
    };

    return (
        <StepScreen
            title={connectTitle(kind)}
            description={
                isAi
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
                    {terms}
                </>
            }
        >
            <SubscriptionOffer
                selectedPlan={selectedPlan}
                onSelect={setSelectedPlan}
            />

            <StepList>
                <AiDisclosure kind={kind} />
                <StepRow
                    icon={kind === 'bank' ? Check : RotateCcw}
                    title={
                        kind === 'bank'
                            ? __('And the rest of Standard')
                            : __('Or keep sorting by hand')
                    }
                    description={
                        kind === 'bank'
                            ? __('Bank sync, unlimited accounts, daily updates')
                            : __('Free, unlimited, and your rules still work')
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
 * promise on the website is that the data is never shared, and this is the one
 * place it partly is.
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

/** The thing the user reached for, named as they named it. */
function connectTitle(kind: GateKind): string {
    return {
        bank: __('Connecting a bank needs Standard'),
        broker: __('Connecting an account needs Standard'),
        ai: __('AI sorting needs Standard'),
    }[kind];
}
