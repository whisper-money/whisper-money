import {
    StepChoice,
    type StepChoiceOption,
} from '@/components/onboarding/step-choice';
import { __ } from '@/utils/i18n';

interface StepTodayProps {
    value?: string;
    onSelect: (value: string) => void;
    onContinue: () => void;
}

/** Mirrors `StoreOnboardingAnswersRequest::CHOICES['today']`. */
const methods = (): StepChoiceOption[] => [
    {
        value: 'head',
        title: __('In my head'),
        description: __('Where almost everyone starts'),
    },
    {
        value: 'spreadsheet',
        title: __('A spreadsheet'),
        description: __("Bring it. We'll import it in a minute"),
    },
    {
        value: 'another-app',
        title: __('Another app'),
        description: __("Export it and we'll take it from there"),
    },
    {
        value: 'none',
        title: __("I don't, and that's the problem"),
        description: __('Then this is going to be a big month'),
    },
];

export function StepToday({ value, onSelect, onContinue }: StepTodayProps) {
    return (
        <StepChoice
            title={__('How do you keep track today?')}
            description={__(
                "There's no wrong answer. It only tells us what you're bringing with you.",
            )}
            options={methods()}
            value={value}
            onSelect={onSelect}
            onContinue={onContinue}
        />
    );
}
