import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { accountIconByType } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Banknote } from 'lucide-react';

interface StepAccountTypesProps {
    onContinue: () => void;
}

const accountTypes = [
    {
        type: 'checking' as const,
        nameKey: 'Checking',
        descriptionKey: 'Daily spending and transactions',
        hasTransactions: true,
    },
    {
        type: 'savings' as const,
        nameKey: 'Savings',
        descriptionKey: 'Save money for goals',
        hasTransactions: true,
    },
    {
        type: 'credit_card' as const,
        nameKey: 'Credit Card',
        descriptionKey: 'Track credit card spending',
        hasTransactions: true,
    },
    {
        type: 'investment' as const,
        nameKey: 'Investment',
        descriptionKey: 'Stocks, ETFs, crypto, and cold wallets',
        hasTransactions: false,
    },
    {
        type: 'retirement' as const,
        nameKey: 'Retirement',
        descriptionKey: '401k, IRA, pension funds',
        hasTransactions: false,
    },
    {
        type: 'loan' as const,
        nameKey: 'Loan',
        descriptionKey: 'Mortgages and loans',
        hasTransactions: false,
    },
    {
        type: 'real_estate' as const,
        nameKey: 'Real Estate',
        descriptionKey: 'Properties and real estate assets',
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
                {accountTypes.map((account) => {
                    const Icon = accountIconByType(account.type);

                    return (
                        <div
                            key={account.type}
                            className="group relative flex flex-row items-center gap-2 overflow-hidden rounded-xl border bg-card p-3 transition-all hover:shadow-md sm:items-start sm:p-4"
                        >
                            <div className="flex w-full flex-col items-start gap-1 sm:gap-2">
                                <div className="flex w-full flex-row items-center justify-between gap-2 sm:items-start">
                                    <div className="flex items-center gap-2">
                                        <Icon
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
                    );
                })}
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
