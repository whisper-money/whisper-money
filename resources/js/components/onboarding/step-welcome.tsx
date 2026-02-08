import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { __ } from '@/utils/i18n';
import { Bird } from 'lucide-react';

interface StepWelcomeProps {
    onContinue: () => void;
}

export function StepWelcome({ onContinue }: StepWelcomeProps) {
    return (
        <div className="flex animate-in flex-col items-center text-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Bird}
                iconContainerClassName="bg-gradient-to-br from-black to-zinc-700"
                title={__('Welcome to</br>Whisper Money')}
                description={__(
                    "Take control of your finances with privacy-first money tracking. Let's set up your account in just a few minutes.",
                )}
                large
            />

            <div className="flex w-full flex-col gap-4 sm:w-auto">
                <StepButton
                    text={__("Let's Get Started")}
                    onClick={onContinue}
                />

                <p className="text-sm text-muted-foreground">
                    {__('This will take less than 5 minutes')}
                </p>
            </div>
        </div>
    );
}
