import {
    StepChoice,
    type StepChoiceOption,
} from '@/components/onboarding/step-choice';
import { __ } from '@/utils/i18n';

interface StepGoalProps {
    value?: string;
    onSelect: (value: string) => void;
    onContinue: () => void;
}

/** Mirrors `StoreOnboardingAnswersRequest::CHOICES['goal']`. */
const goals = (): StepChoiceOption[] => [
    {
        value: 'keep-more',
        title: __('Keep more at the end of the month'),
        description: __("We'll put a number on what's left over"),
    },
    {
        value: 'understand',
        title: __('Understand where it all goes'),
        description: __('Twelve months, sorted, in one view'),
    },
    {
        value: 'debt',
        title: __('Get out from under a debt'),
        description: __("See what's going out before it goes"),
    },
    {
        value: 'save-for',
        title: __('Save for something specific'),
        description: __('A move, a trip, a year off'),
    },
];

export function StepGoal({ value, onSelect, onContinue }: StepGoalProps) {
    return (
        <StepChoice
            title={__('What do you want to change?')}
            description={__(
                'This decides what your dashboard opens on, and what your first target will be.',
            )}
            options={goals()}
            value={value}
            onSelect={onSelect}
            onContinue={onContinue}
        />
    );
}

/** The chosen goal as the plan step reads it back. */
export function goalLabel(value: string | undefined): string | undefined {
    return goals()
        .find((goal) => goal.value === value)
        ?.title.toLowerCase();
}
