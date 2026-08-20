import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { Sparkles, Zap } from 'lucide-react';

interface StepSmartRulesProps {
    onContinue: () => void;
}

export function StepSmartRules({ onContinue }: StepSmartRulesProps) {
    return (
        <StepScreen
            title={__('Smart Automation Rules')}
            description={__(
                'Create rules to automatically categorize your transactions based on patterns you define.',
            )}
            footer={<StepButton text={__('Continue')} onClick={onContinue} />}
        >
            <StepList>
                <StepRow
                    icon={Sparkles}
                    title={__('Pattern Matching')}
                    description={__(
                        'Create rules like "If description contains \'AMAZON\', categorize as Shopping"',
                    )}
                />
                <StepRow
                    icon={Zap}
                    title={__('Instant Application')}
                    description={__(
                        'Rules apply automatically when you import new transactions',
                    )}
                />
            </StepList>
        </StepScreen>
    );
}
