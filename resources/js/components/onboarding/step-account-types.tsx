import { StepButton } from '@/components/onboarding/step-button';
import {
    StepBadge,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { accountIconByType } from '@/types/account';
import { __ } from '@/utils/i18n';

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
        descriptionKey: 'Stocks, ETFs and crypto',
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
        <StepScreen
            title={__('Account Types')}
            description={__(
                'Some track every transaction, others only the balance over time.',
            )}
            footer={
                <StepButton
                    text={__('Create Your First Account')}
                    onClick={onContinue}
                />
            }
        >
            <StepList>
                {accountTypes.map((account) => (
                    <StepRow
                        key={account.type}
                        icon={accountIconByType(account.type)}
                        title={__(account.nameKey)}
                        description={__(account.descriptionKey)}
                        trailing={
                            <StepBadge>
                                {account.hasTransactions
                                    ? __('Transactions')
                                    : __('Balance')}
                            </StepBadge>
                        }
                    />
                ))}
            </StepList>
        </StepScreen>
    );
}
