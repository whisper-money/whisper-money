import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { ArrowDownLeft, ArrowUpRight, Repeat } from 'lucide-react';

interface StepCategoryTypesProps {
    onContinue: () => void;
}

const categoryTypes = [
    {
        type: 'expense',
        nameKey: 'Expense',
        icon: ArrowUpRight,
        descriptionKey:
            'Money going out of an account to pay for something (e.g., groceries, rent, subscriptions). Decreases your balance.',
    },
    {
        type: 'income',
        nameKey: 'Income',
        icon: ArrowDownLeft,
        descriptionKey:
            'Money coming into an account from a source (e.g., salary, refunds, interest). Increases your balance.',
    },
    {
        type: 'transfer',
        nameKey: 'Transfer',
        icon: Repeat,
        descriptionKey:
            'Moving money between accounts. It does not count in expenses or income charts.',
    },
];

export function StepCategoryTypes({ onContinue }: StepCategoryTypesProps) {
    return (
        <StepScreen
            title={__('Understanding Categories')}
            description={__('Every transaction belongs to one of three types:')}
            footer={<StepButton text={__('Continue')} onClick={onContinue} />}
        >
            <StepList>
                {categoryTypes.map((category) => (
                    <StepRow
                        key={category.type}
                        icon={category.icon}
                        title={__(category.nameKey)}
                        description={__(category.descriptionKey)}
                    />
                ))}
            </StepList>
        </StepScreen>
    );
}
