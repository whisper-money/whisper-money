import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { dashboard } from '@/routes';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useEffect, useState } from 'react';

/** How long to wait for Stripe's webhook before letting the user through anyway. */
const MAX_ACTIVATION_POLLS = 5;
const ACTIVATION_POLL_MS = 2000;

/**
 * Where Stripe drops the user after a successful checkout.
 *
 * Stripe redirects here as soon as payment succeeds, but the local
 * subscription row is only written when its webhook lands — and the dashboard
 * sits behind `EnsureUserIsSubscribed`, which sends anyone without an active
 * plan back to the paywall. So the wait here is real, driven by the actual
 * subscription state rather than by a timer, and bounded: if the webhook is
 * slow beyond all reason, the user gets the button rather than a trap.
 */
export default function Success() {
    const { auth } = usePage<SharedData>().props;
    const isActive = auth?.hasProPlan ?? false;
    const [polls, setPolls] = useState(0);
    const isWaiting = !isActive && polls < MAX_ACTIVATION_POLLS;

    useEffect(() => {
        if (!isWaiting) {
            return;
        }

        const timer = setTimeout(() => {
            setPolls((count) => count + 1);
            router.reload({ only: ['auth'] });
        }, ACTIVATION_POLL_MS);

        return () => clearTimeout(timer);
    }, [isWaiting, polls]);

    return (
        <SubscriptionLayout>
            <Head title={__('Your plan is active')} />

            <StepScreen
                title={__('Payment received')}
                description={__(
                    'Your plan is on. Connected banks, AI suggestions and the AI assistant are all available now.',
                )}
                footer={
                    <>
                        <StepButton
                            text={__('Go to Dashboard')}
                            loading={isWaiting}
                            loadingText={__('Activating your plan…')}
                            onClick={() => router.visit(dashboard().url)}
                        />
                        {!isActive && polls >= MAX_ACTIVATION_POLLS && (
                            <StepNote>
                                {__(
                                    'Your payment went through. If anything still looks locked, reload in a minute.',
                                )}
                            </StepNote>
                        )}
                    </>
                }
            >
                <StepList>
                    <StepRow icon={Check} title={__('Connected banks')} />
                    <StepRow icon={Check} title={__('AI suggestions')} />
                    <StepRow icon={Check} title={__('Your AI assistant')} />
                </StepList>

                <StepNote>
                    {__(
                        'Banks sync a few times a day. To pull yours in right now, go to Settings → Connections.',
                    )}
                </StepNote>
            </StepScreen>
        </SubscriptionLayout>
    );
}
