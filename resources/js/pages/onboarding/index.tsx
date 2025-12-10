import { StepAccountTypes } from '@/components/onboarding/step-account-types';
import { StepCategoryTypes } from '@/components/onboarding/step-category-types';
import { StepComplete } from '@/components/onboarding/step-complete';
import { StepCreateAccount } from '@/components/onboarding/step-create-account';
import { StepCustomizeCategories } from '@/components/onboarding/step-customize-categories';
import { StepEncryptionExplained } from '@/components/onboarding/step-encryption-explained';
import { StepEncryptionSetup } from '@/components/onboarding/step-encryption-setup';
import { StepImportBalances } from '@/components/onboarding/step-import-balances';
import { StepImportTransactions } from '@/components/onboarding/step-import-transactions';
import { StepMoreAccounts } from '@/components/onboarding/step-more-accounts';
import { StepSmartRules } from '@/components/onboarding/step-smart-rules';
import { StepWelcome } from '@/components/onboarding/step-welcome';
import {
    CreatedAccount,
    OnboardingStep,
    useOnboardingState,
} from '@/hooks/use-onboarding-state';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { type Bank } from '@/types/account';
import { Head } from '@inertiajs/react';

interface OnboardingProps {
    banks: Bank[];
}

export default function Onboarding({ banks }: OnboardingProps) {
    const {
        currentStep,
        stepIndex,
        totalSteps,
        createdAccounts,
        isFirstAccount,
        goToStep,
        goNext,
        addCreatedAccount,
    } = useOnboardingState();

    const handleAccountCreated = (account: CreatedAccount) => {
        addCreatedAccount(account);

        const needsTransactionImport = [
            'checking',
            'savings',
            'credit_card',
        ].includes(account.type);

        if (needsTransactionImport) {
            goToStep('import-transactions');
        } else {
            goToStep('import-balances');
        }
    };

    const handleImportComplete = () => {
        goToStep('more-accounts');
    };

    const handleAddMoreAccounts = () => {
        goToStep('create-account');
    };

    const handleFinishOnboarding = () => {
        goToStep('complete');
    };

    const renderStep = () => {
        const lastAccount = createdAccounts[createdAccounts.length - 1];

        switch (currentStep) {
            case 'welcome':
                return <StepWelcome onContinue={goNext} />;

            case 'encryption-explained':
                return <StepEncryptionExplained onContinue={goNext} />;

            case 'encryption-setup':
                return <StepEncryptionSetup onComplete={goNext} />;

            case 'account-types':
                return <StepAccountTypes onContinue={goNext} />;

            case 'create-account':
                return (
                    <StepCreateAccount
                        banks={banks}
                        isFirstAccount={isFirstAccount}
                        onAccountCreated={handleAccountCreated}
                    />
                );

            case 'category-types':
                return <StepCategoryTypes onContinue={goNext} />;

            case 'customize-categories':
                return (
                    <StepCustomizeCategories
                        onContinue={goNext}
                        onSkip={goNext}
                    />
                );

            case 'smart-rules':
                return <StepSmartRules onContinue={goNext} />;

            case 'import-transactions':
                return (
                    <StepImportTransactions
                        account={lastAccount}
                        onComplete={handleImportComplete}
                    />
                );

            case 'import-balances':
                return (
                    <StepImportBalances
                        account={lastAccount}
                        onComplete={handleImportComplete}
                    />
                );

            case 'more-accounts':
                return (
                    <StepMoreAccounts
                        createdAccounts={createdAccounts}
                        onAddMore={handleAddMoreAccounts}
                        onFinish={handleFinishOnboarding}
                    />
                );

            case 'complete':
                return <StepComplete />;

            default:
                return null;
        }
    };

    const getStepTitle = (step: OnboardingStep): string => {
        const titles: Record<OnboardingStep, string> = {
            welcome: 'Welcome',
            'encryption-explained': 'End-to-End Encryption',
            'encryption-setup': 'Setup Encryption',
            'account-types': 'Account Types',
            'create-account': 'Create Account',
            'category-types': 'Categories',
            'customize-categories': 'Customize Categories',
            'smart-rules': 'Smart Rules',
            'import-transactions': 'Import Transactions',
            'import-balances': 'Set Balance',
            'more-accounts': 'Add More Accounts',
            complete: 'All Set!',
        };
        return titles[step];
    };

    return (
        <>
            <Head title={`Onboarding - ${getStepTitle(currentStep)}`} />
            <OnboardingLayout
                currentStep={stepIndex}
                totalSteps={totalSteps}
                stepKey={currentStep}
            >
                {renderStep()}
            </OnboardingLayout>
        </>
    );
}
