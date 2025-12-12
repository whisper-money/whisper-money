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
        description: 'Money going out',
        examples: ['Food', 'Rent', 'Entertainment', 'Transport'],
        color: 'from-red-500 to-rose-500',
        bgColor: 'bg-red-50 dark:bg-red-900/20',
        textColor: 'text-red-700 dark:text-red-400',
    },
    {
        type: 'income',
        name: 'Income',
        icon: ArrowDownLeft,
        description: 'Money coming in',
        examples: ['Salary', 'Freelance', 'Investments', 'Refunds'],
        color: 'from-emerald-500 to-green-500',
        bgColor: 'bg-emerald-50 dark:bg-emerald-900/20',
        textColor: 'text-emerald-700 dark:text-emerald-400',
    },
    {
        type: 'transfer',
        name: 'Transfer',
        icon: Repeat,
        description: 'Moving money between accounts',
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
                description="Categories help you organize and understand your spending. Every transaction belongs to one of three types:"
            />

            <div className="mb-8 grid w-full max-w-3xl gap-4 md:grid-cols-3">
                {categoryTypes.map((category) => (
                    <div
                        key={category.type}
                        className="flex flex-col items-center rounded-xl border bg-card p-6 text-center"
                    >
                        <div
                            className={`mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br ${category.color}`}
                        >
                            <category.icon className="h-7 w-7 text-white" />
                        </div>
                        <h3 className="mb-2 text-xl font-semibold">
                            {category.name}
                        </h3>
                        <p className="mb-4 text-sm text-muted-foreground">
                            {category.description}
                        </p>
                        <div className="flex flex-wrap justify-center gap-1">
                            {category.examples.map((example) => (
                                <span
                                    key={example}
                                    className={`rounded-full px-2 py-0.5 text-xs ${category.bgColor} ${category.textColor}`}
                                >
                                    {example}
                                </span>
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            <div className="mb-8 max-w-xl rounded-lg border border-violet-200 bg-violet-50 p-4 dark:border-violet-900/50 dark:bg-violet-900/20">
                <p className="text-center text-sm text-violet-800 dark:text-violet-200">
                    <strong>Tip:</strong> We've already set up 50+ common
                    categories for you. You can customize them in the next step
                    or anytime from settings.
                </p>
            </div>

            <StepButton text="Continue" onClick={onContinue} />
        </div>
    );
}
