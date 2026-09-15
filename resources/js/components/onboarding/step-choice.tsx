import { StepButton } from '@/components/onboarding/step-button';
import {
    StepCheck,
    StepChevron,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';

export interface StepChoiceOption {
    value: string;
    title: string;
    description: string;
}

interface StepChoiceProps {
    title: string;
    description: string;
    options: StepChoiceOption[];
    /** The answer already given, from a resumed run or a step gone back to. */
    value?: string;
    onSelect: (value: string) => void;
    onContinue: () => void;
}

/**
 * A single-answer question: the list is the whole screen, and the action stays
 * shut until one of the rows has been picked.
 *
 * The onboarding asks two of these back to back, and they are the same screen
 * with different words in it.
 */
export function StepChoice({
    title,
    description,
    options,
    value,
    onSelect,
    onContinue,
}: StepChoiceProps) {
    return (
        <StepScreen
            title={title}
            description={description}
            footer={
                <StepButton
                    text={__('Continue')}
                    onClick={onContinue}
                    disabled={value === undefined}
                />
            }
        >
            <StepList>
                {options.map((option) => (
                    <StepRow
                        key={option.value}
                        title={option.title}
                        description={option.description}
                        onClick={() => onSelect(option.value)}
                        trailing={
                            option.value === value ? (
                                <StepCheck />
                            ) : (
                                <StepChevron />
                            )
                        }
                    />
                ))}
            </StepList>
        </StepScreen>
    );
}
