import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { __ } from '@/utils/i18n';
import {
    Banknote,
    Building2,
    CreditCard,
    LineChart,
    PiggyBank,
    TrendingUp,
    Wallet,
} from 'lucide-react';

interface StepAccountTypesProps {
    onContinue: () => void;
}

const accountTypes = [
    {
        type: 'checking',
        nameKey: 'Checking',
        icon: Wallet,
        descriptionKey: 'Daily spending and transactions',
        hasTransactions: true,
    },
    {
        type: 'savings',
        nameKey: 'Savings',
        icon: PiggyBank,
        descriptionKey: 'Save money for goals',
        hasTransactions: true,
    },
    {
        type: 'credit_card',
        nameKey: 'Credit Card',
        icon: CreditCard,
        descriptionKey: 'Track credit card spending',
        hasTransactions: true,
    },
    {
        type: 'investment',
        nameKey: 'Investment',
        icon: LineChart,
        descriptionKey: 'Stocks, ETFs, and portfolios',
        hasTransactions: false,
    },
    {
        type: 'retirement',
        nameKey: 'Retirement',
        icon: TrendingUp,
        descriptionKey: '401k, IRA, pension funds',
        hasTransactions: false,
    },
    {
        type: 'loan',
        nameKey: 'Loan',
        icon: Building2,
        descriptionKey: 'Mortgages and loans',
        hasTransactions: false,
    },
];

export function StepAccountTypes({ onContinue }: StepAccountTypesProps) {
    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Banknote}
                iconContainerClassName="bg-gradient-to-br from-cyan-400 to-blue-500"
                title={__('Account Types')}
                description={__(
                    'There are different account types. Some track transactions, others just track balance over time.',
                )}
            />

            <div className="grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                {accountTypes.map((account) => (
                    <div
                        key={account.type}
                        className="group relative flex flex-row items-center gap-2 overflow-hidden rounded-xl border bg-card p-3 transition-all hover:shadow-md sm:items-start sm:p-4"
                    >
                        <div className="flex w-full flex-col items-start gap-1 sm:gap-2">
                            <div className="flex w-full flex-row items-center justify-between gap-2 sm:items-start">
                                <div className="flex items-center gap-2">
                                    <account.icon
                                        className={`size-4 stroke-muted-foreground`}
                                    />

                                    <h3 className="font-semibold">
                                        {__(account.nameKey)}
                                    </h3>
                                </div>

                                <span
                                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${
                                        account.hasTransactions
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'
                                    }`}
                                >
                                    {account.hasTransactions
                                        ? __('Transactions + Balance')
                                        : __('Balance')}
                                </span>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {__(account.descriptionKey)}
                            </p>
                        </div>
                    </div>
                ))}
            </div>

            <div className="mt-8 w-full sm:w-auto">
                <StepButton
                    text={__('Create Your First Account')}
                    onClick={onContinue}
                />
            </div>
        </div>
    );
}
