import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { ArrowDownLeft, ArrowUpRight, Repeat, Tag } from 'lucide-react';

interface StepCategoryTypesProps {
    onContinue: () => void;
}

const categoryTypes = [
    {
        type: 'expense',
        name: 'Expense',
        icon: ArrowUpRight,
        description: 'Money going out of an account to pay for something (e.g., groceries, rent, subscriptions). Decreases your balance.',
        examples: ['Food', 'Rent', 'Entertainment', 'Transport'],
        color: 'from-red-500 to-rose-500',
        bgColor: 'bg-red-50 dark:bg-red-900/20',
        textColor: 'text-red-700 dark:text-red-400',
    },
    {
        type: 'income',
        name: 'Income',
        icon: ArrowDownLeft,
        description: 'Money coming into an account from a source (e.g., salary, refunds, interest). Increases your balance.',
        examples: ['Salary', 'Freelance', 'Investments', 'Refunds'],
        color: 'from-emerald-500 to-green-500',
        bgColor: 'bg-emerald-50 dark:bg-emerald-900/20',
        textColor: 'text-emerald-700 dark:text-emerald-400',
    },
    {
        type: 'transfer',
        name: 'Transfer',
        icon: Repeat,
        description: 'Moving money between accounts. It does not count in expenses or income charts.',
        examples: ['To savings', 'Credit card payment', 'Between banks'],
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
                title="Understanding Categories"
                description="Every transaction belongs to one of three types:"
            />

            <div className="mb-8 grid w-full max-w-3xl gap-4 md:grid-cols-3">
                {categoryTypes.map((category) => (
                    <div
                        key={category.type}
                        className="flex flex-col gap-2 items-start rounded-xl border bg-card p-6 text-center"
                    >
                        <div className='flex gap-2 flex-row items-center justify-center'>
                            <div
                                className={`flex size-5 items-center justify-center rounded-full bg-gradient-to-br ${category.color}`}
                            >
                                <category.icon className="size-4 text-white" />
                            </div>
                            <h3 className="font-semibold text-base">
                                {category.name}
                            </h3>
                        </div>

                        <p className="w-full text-left text-muted-foreground/75">
                            {category.description}
                        </p>
                    </div>
                ))}
            </div>

            <StepButton text="Continue" onClick={onContinue} />
        </div>
    );
}
