import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { __ } from '@/utils/i18n';
import { ArrowDownLeft, ArrowUpRight, Repeat, Tag } from 'lucide-react';

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
        color: 'from-red-500 to-rose-500',
        bgColor: 'bg-red-50 dark:bg-red-900/20',
        textColor: 'text-red-700 dark:text-red-400',
    },
    {
        type: 'income',
        nameKey: 'Income',
        icon: ArrowDownLeft,
        descriptionKey:
            'Money coming into an account from a source (e.g., salary, refunds, interest). Increases your balance.',
        color: 'from-emerald-500 to-green-500',
        bgColor: 'bg-emerald-50 dark:bg-emerald-900/20',
        textColor: 'text-emerald-700 dark:text-emerald-400',
    },
    {
        type: 'transfer',
        nameKey: 'Transfer',
        icon: Repeat,
        descriptionKey:
            'Moving money between accounts. It does not count in expenses or income charts.',
        color: 'from-blue-500 to-cyan-500',
        bgColor: 'bg-blue-50 dark:bg-blue-900/20',
        textColor: 'text-blue-700 dark:text-blue-400',
    },
];

export function StepCategoryTypes({ onContinue }: StepCategoryTypesProps) {
    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Tag}
                iconContainerClassName="bg-gradient-to-br from-violet-400 to-purple-500"
                title={__('Understanding Categories')}
                description={__(
                    'Every transaction belongs to one of three types:',
                )}
            />

            <div className="mb-8 grid w-full max-w-3xl gap-4 md:grid-cols-3">
                {categoryTypes.map((category) => (
                    <div
                        key={category.type}
                        className="flex flex-col items-start gap-2 rounded-xl border bg-card p-6 text-center"
                    >
                        <div className="flex flex-row items-center justify-center gap-2">
                            <div
                                className={`flex size-5 items-center justify-center rounded-full bg-gradient-to-br ${category.color}`}
                            >
                                <category.icon className="size-4 text-white" />
                            </div>
                            <h3 className="text-base font-semibold">
                                {__(category.nameKey)}
                            </h3>
                        </div>

                        <p className="w-full text-left text-muted-foreground/75">
                            {__(category.descriptionKey)}
                        </p>
                    </div>
                ))}
            </div>

            <StepButton text={__('Continue')} onClick={onContinue} />
        </div>
    );
}
