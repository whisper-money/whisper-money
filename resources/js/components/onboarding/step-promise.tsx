import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { ChartColumn, DollarSign, Lock } from 'lucide-react';

interface StepPromiseProps {
    onContinue: () => void;
}

/**
 * The first screen, and a claim rather than a welcome: it says what the user
 * walks away with, and what the three questions after it are for.
 */
export function StepPromise({ onContinue }: StepPromiseProps) {
    return (
        <StepScreen
            title={__('Find out where your money actually went')}
            description={__(
                'Not a budget to maintain. Not a habit to build. Twelve months of your own spending, read back to you in about three minutes.',
            )}
            footer={
                <>
                    <StepButton text={__('Start')} onClick={onContinue} />
                    <StepNote>
                        {__('Three questions first. They change what you see.')}
                    </StepNote>
                </>
            }
        >
            <StepList>
                <StepRow
                    icon={DollarSign}
                    title={__('A year of history, not a blank page')}
                    description={__(
                        'Your bank already has it. We just read it.',
                    )}
                />
                <StepRow
                    icon={ChartColumn}
                    title={__('Sorted before you see it')}
                    description={__('You correct what we get wrong, once.')}
                />
                <StepRow
                    icon={Lock}
                    title={__('Yours, and only yours')}
                    description={__(
                        "We don't sell your data, share it, or advertise against it. That's the business model.",
                    )}
                />
            </StepList>
        </StepScreen>
    );
}
