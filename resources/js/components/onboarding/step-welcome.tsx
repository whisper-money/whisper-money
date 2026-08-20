import { StepButton } from '@/components/onboarding/step-button';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';

interface StepWelcomeProps {
    onContinue: () => void;
}

export function StepWelcome({ onContinue }: StepWelcomeProps) {
    return (
        <StepScreen
            title={__('Welcome to Whisper Money')}
            description={__(
                "Take control of your finances with privacy-first money tracking. Let's set up your account in just a few minutes.",
            )}
            footer={
                <>
                    <StepButton
                        text={__("Let's Get Started")}
                        onClick={onContinue}
                    />
                    <StepNote>
                        {__('This will take less than 5 minutes')}
                    </StepNote>
                </>
            }
        />
    );
}
