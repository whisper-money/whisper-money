import { complete } from '@/actions/App/Http/Controllers/OnboardingController';
import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';

export function StepComplete() {
    const [isRedirecting, setIsRedirecting] = useState(false);

    const handleComplete = () => {
        setIsRedirecting(true);

        router.post(
            complete.url(),
            {},
            {
                onError: () => {
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
                <StepRow icon={Check} title={__('Data Imported')} />
            </StepList>
        </StepScreen>
    );
}
