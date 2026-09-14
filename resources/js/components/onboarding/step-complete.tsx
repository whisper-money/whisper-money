import { complete } from '@/actions/App/Http/Controllers/OnboardingController';
import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { clearStoredOnboardingStep } from '@/hooks/use-onboarding-state';
import { captureEvent } from '@/lib/posthog';
import { type SignupPlan } from '@/types/pricing';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';

interface StepCompleteProps {
    /** Accounts added during this run of the wizard, not counting earlier ones. */
    accountsCreated: number;
    hasConnectedAccount: boolean;
    signupPlan: SignupPlan | null;
}

export function StepComplete({
    accountsCreated,
    hasConnectedAccount,
    signupPlan,
}: StepCompleteProps) {
    const [isRedirecting, setIsRedirecting] = useState(false);

    const handleComplete = () => {
        setIsRedirecting(true);

        router.post(
            complete.url(),
            {},
            {
                // Onboarding is over, so the locally stored resume step has to
                // go with it or a later visit drags the user back in.
                //
                // The event rides along here rather than on the click: only a
                // request the server accepted actually stamped `onboarded_at`,
                // and the button is deliberately retryable after a failure.
                onSuccess: () => {
                    clearStoredOnboardingStep();
                    captureEvent('onboarding_completed', {
                        accounts_created: accountsCreated,
                        has_connected_account: hasConnectedAccount,
                        signup_plan: signupPlan,
                    });
                },
                // `onError` only fires for a response Inertia could read, so a
                // request that died on the network left the last step of
                // onboarding behind a spinning, disabled button with no way out
                // but a page reload. `onFinish` runs on every outcome, so the
                // toast that already explains the failure comes with a button
                // the user can press again.
                onFinish: () => {
                    setIsRedirecting(false);
                },
            },
        );
    };

    return (
        <StepScreen
            title={__("You're All Set!")}
            description={__(
                'Your accounts are ready and your categories are set up. Welcome to Whisper Money!',
            )}
            footer={
                <StepButton
                    text={__('Go to Dashboard')}
                    onClick={handleComplete}
                    loading={isRedirecting}
                    loadingText={__('Redirecting...')}
                />
            }
        >
            <StepList>
                <StepRow icon={Check} title={__('Accounts Created')} />
                <StepRow icon={Check} title={__('Categories Ready')} />
            </StepList>
        </StepScreen>
    );
}
