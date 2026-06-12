import { StepAccountTypes } from '@/components/onboarding/step-account-types';
import { StepAiSuggestions } from '@/components/onboarding/step-ai-suggestions';
import { StepCategorizeTransactions } from '@/components/onboarding/step-categorize-transactions';
import { StepCategoryTypes } from '@/components/onboarding/step-category-types';
import { StepComplete } from '@/components/onboarding/step-complete';
import { StepCreateAccount } from '@/components/onboarding/step-create-account';
import { StepCustomizeCategories } from '@/components/onboarding/step-customize-categories';
import { StepImportBalances } from '@/components/onboarding/step-import-balances';
import { StepImportTransactions } from '@/components/onboarding/step-import-transactions';
import { StepSmartRules } from '@/components/onboarding/step-smart-rules';
import { StepSyncing } from '@/components/onboarding/step-syncing';
import { StepWelcome } from '@/components/onboarding/step-welcome';
import { useSyncContext } from '@/contexts/sync-context';
import {
    CreatedAccount,
    OnboardingStep,
    useOnboardingState,
} from '@/hooks/use-onboarding-state';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type Transaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Head, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';

interface ExistingAccount {
    id: string;
    name: string;
    name_iv: string | null;
    encrypted: boolean;
    type: string;
    currency_code: string;
    bank_id: string;
    banking_connection_id: string | null;
    bank?: {
        id: string;
        name: string;
        logo: string | null;
    };
}

interface OnboardingProps {
    banks: Bank[];
    accounts: ExistingAccount[];
    categories: Category[];
    transactions: Transaction[];
    initialStep?: OnboardingStep | null;
}

const VALID_STEPS: OnboardingStep[] = [
    'welcome',
    'account-types',
    'create-account',
    'import-transactions',
    'import-balances',
    'category-types',
    'customize-categories',
    'smart-rules',
    'syncing',
    'ai-suggestions',
    'categorize-transactions',
    'complete',
];

export default function Onboarding({
    banks,
    accounts,
    categories,
    transactions,
    initialStep: initialStepProp,
}: OnboardingProps) {
    const { sync } = useSyncContext();
    const hasSyncedRef = useRef(false);

    // Prefer the server-validated step; fall back to ?step= from the URL so
    // client-side deep links keep working.
    const initialStep = useMemo((): OnboardingStep | undefined => {
        if (initialStepProp && VALID_STEPS.includes(initialStepProp)) {
            return initialStepProp;
        }
        if (typeof window === 'undefined') {
            return undefined;
        }
        const params = new URLSearchParams(window.location.search);
        const step = params.get('step') as OnboardingStep | null;
        return step && VALID_STEPS.includes(step) ? step : undefined;
    }, [initialStepProp]);

    // Sync banks on mount to ensure IndexedDB has the latest data
    useEffect(() => {
        if (!hasSyncedRef.current) {
            hasSyncedRef.current = true;
            sync();
        }
    }, [sync]);

    const hasConnectedAccount = useMemo(
        () =>
            accounts.some((account) => account.banking_connection_id !== null),
        [accounts],
    );

    const {
        currentStep,
        stepIndex,
        totalSteps,
        createdAccounts,
        isFirstAccount,
        hasSelectedConnectedAccount,
        goToStep,
        goNext,
        addCreatedAccount,
        markConnectedAccountSelected,
    } = useOnboardingState({
        existingAccountsCount: accounts.length,
        initialStep,
        hasConnectedAccount,
    });

    // While on the connections step, poll for connections finalized elsewhere
    // (e.g. an iOS PWA hands the bank redirect to Safari, which completes the
    // connection server-side in a different browser without a session here).
    const { start, stop } = usePoll(
        4000,
        { only: ['accounts'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (currentStep === 'create-account') {
            start();
        } else {
            stop();
        }
    }, [currentStep, start, stop]);

    const handleAccountCreated = async (account: CreatedAccount) => {
        // Connected accounts already exist server-side (in existingAccounts prop);
        // don't add them to createdAccounts — they'll show via filteredExistingAccounts.
        if (!account.connected) {
            addCreatedAccount(account);
        }

        // Sync with backend to get the new account in local DB
        await sync();

        // Connected accounts (bank-linked) don't need manual import steps
        if (account.connected) {
            addCreatedAccount(account);
            goToStep('create-account');
            return;
        }

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

    const handleImportComplete = async () => {
        // Sync after import to ensure data is consistent
        await sync();

        // Always return to create-account so the user can add more accounts or continue
        goToStep('create-account');
    };

    const renderStep = () => {
        const lastAccount = createdAccounts[createdAccounts.length - 1];

        switch (currentStep) {
            case 'welcome':
                return <StepWelcome onContinue={goNext} />;

            case 'account-types':
                return <StepAccountTypes onContinue={goNext} />;

            case 'create-account':
                return (
                    <StepCreateAccount
                        key={createdAccounts.length}
                        banks={banks}
                        isFirstAccount={isFirstAccount}
                        existingAccounts={accounts}
                        createdAccounts={createdAccounts}
                        hasSelectedConnectedAccount={
                            hasSelectedConnectedAccount
                        }
                        onAccountCreated={handleAccountCreated}
                        onConnectedAccountSelected={
                            markConnectedAccountSelected
                        }
                        onContinue={goNext}
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

            case 'syncing':
                return <StepSyncing onComplete={goNext} />;

            case 'ai-suggestions':
                return (
                    <StepAiSuggestions
                        categories={categories}
                        onComplete={goNext}
                    />
                );

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

            case 'categorize-transactions':
                return (
                    <StepCategorizeTransactions
                        categories={categories}
                        accounts={accounts as unknown as Account[]}
                        banks={banks}
                        transactions={transactions}
                        onComplete={goNext}
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
            welcome: __('Welcome'),
            'account-types': __('Account Types'),
            'create-account': __('Create Account'),
            'category-types': __('Categories'),
            'customize-categories': __('Customize Categories'),
            'smart-rules': __('Smart Rules'),
            syncing: __('Syncing'),
            'ai-suggestions': __('AI Suggestions'),
            'import-transactions': __('Import Transactions'),
            'import-balances': __('Set Balance'),
            'categorize-transactions': __('Categorize Transactions'),
            complete: __('All Set!'),
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
