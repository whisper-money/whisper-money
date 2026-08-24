import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { __ } from '@/utils/i18n';
import { Check } from 'lucide-react';

interface StepCustomizeCategoriesProps {
    onContinue: () => void;
}

const previewCategories = [
    {
        nameKey: 'Food & Dining',
        childrenKey: 'Groceries, Restaurants, Delivery',
    },
    { nameKey: 'Housing', childrenKey: 'Rent, Utilities, Maintenance' },
    { nameKey: 'Transportation', childrenKey: 'Fuel, Public Transit, Parking' },
    { nameKey: 'Shopping', childrenKey: 'Clothing, Electronics, Gifts' },
    { nameKey: 'Entertainment', childrenKey: 'Movies, Sports, Hobbies' },
    { nameKey: 'Health & Wellness', childrenKey: 'Medical, Pharmacy, Fitness' },
    { nameKey: 'Income', childrenKey: 'Salary, Freelance, Investments' },
    { nameKey: 'Transfers', childrenKey: 'Between accounts, Savings' },
];

export function StepCustomizeCategories({
    onContinue,
}: StepCustomizeCategoriesProps) {
    return (
        <StepScreen
            title={__('Customize Your Categories')}
            description={__(
                "We've created a complete set for you. Use it as it is and adjust it whenever you want.",
            )}
            footer={<StepButton text={__('Continue')} onClick={onContinue} />}
        >
            <div className="flex flex-col gap-5">
                <StepList>
                    {previewCategories.map((category) => (
                        <StepRow
                            key={category.nameKey}
                            icon={Check}
                            title={__(category.nameKey)}
                            description={__(category.childrenKey)}
                        />
                    ))}
                    <StepRow title={__('...and 40+ more')} />
                </StepList>

                <p className="text-[13px] leading-normal text-muted-foreground">
                    {__(
                        'You can create, rename and delete categories in Settings at any time.',
                    )}
                </p>
            </div>
        </StepScreen>
    );
}
