import {
    StepAccountsHub,
    type ExistingAccount,
} from '@/components/onboarding/step-accounts-hub';
import { StepAiSuggestions } from '@/components/onboarding/step-ai-suggestions';
import { StepCategorizeTransactions } from '@/components/onboarding/step-categorize-transactions';
import { StepComplete } from '@/components/onboarding/step-complete';
import { goalLabel, StepGoal } from '@/components/onboarding/step-goal';
import { StepGuess } from '@/components/onboarding/step-guess';
import { StepImportBalances } from '@/components/onboarding/step-import-balances';
import { StepImportTransactions } from '@/components/onboarding/step-import-transactions';
import { type PendingMapping } from '@/components/onboarding/step-map-accounts';
import { StepPlan } from '@/components/onboarding/step-plan';
import { StepPromise } from '@/components/onboarding/step-promise';
import { StepReveal } from '@/components/onboarding/step-reveal';
import { StepSyncing } from '@/components/onboarding/step-syncing';
import { StepTarget } from '@/components/onboarding/step-target';
import { StepToday } from '@/components/onboarding/step-today';
import { useSyncContext } from '@/contexts/sync-context';
import {
    BACKABLE_STEPS,
    CreatedAccount,
    OnboardingStep,
    useOnboardingState,
    validStepsFor,
    type OnboardingAnswers,
} from '@/hooks/use-onboarding-state';
import OnboardingLayout from '@/layouts/onboarding-layout';
import { type SharedData } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type SignupPlan } from '@/types/pricing';
import { type Transaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Head, usePage, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';

interface OnboardingProps {
    banks: Bank[];
    accounts: ExistingAccount[];
    categories: Category[];
    transactions: Transaction[];
    initialStep?: OnboardingStep | null;
    onboardingAnswers?: OnboardingAnswers;
    signupPlan?: SignupPlan | null;
    pendingMapping?: PendingMapping | null;
}

export default function Onboarding({
    banks,
    accounts,
    categories,
    transactions,
    initialStep: initialStepProp,
    onboardingAnswers = {},
    signupPlan = null,
    pendingMapping = null,
}: OnboardingProps) {
    const { sync } = useSyncContext();
    const { auth } = usePage<SharedData>().props;
    const hasSyncedRef = useRef(false);
    const isFreePlan = signupPlan === 'free';

    // Prefer the server-validated step; fall back to ?step= from the URL so
    // client-side deep links keep working. Neither is the hook's last resort:
    // it falls back again to the step it stored locally.
    const initialStep = useMemo((): OnboardingStep | undefined => {
        const validSteps = validStepsFor(isFreePlan);

        if (initialStepProp && validSteps.includes(initialStepProp)) {
            return initialStepProp;
        }
        if (typeof window === 'undefined') {
            return undefined;
        }
        const params = new URLSearchParams(window.location.search);
        const step = params.get('step') as OnboardingStep | null;
        return step && validSteps.includes(step) ? step : undefined;
    }, [initialStepProp, isFreePlan]);

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
        answers,
        saveAnswer,
        createdAccounts,
        isFirstAccount,
        hasSelectedConnectedAccount,
        goToStep,
        goNext,
        goBack,
        addCreatedAccount,
        markConnectedAccountSelected,
    } = useOnboardingState({
        existingAccountsCount: accounts.length,
        initialStep,
        hasConnectedAccount,
        skipAiSuggestions: isFreePlan,
        userId: auth.user.id,
        signupPlan,
        initialAnswers: onboardingAnswers,
    });

    // While on the connections step, poll for connections finalized elsewhere
    // (e.g. an iOS PWA hands the bank redirect to Safari, which completes the
    // connection server-side in a different browser without a session here).
    const { start, stop } = usePoll(
        4000,
        { only: ['accounts', 'pendingMapping'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (currentStep === 'create-account' && !isFreePlan) {
            start();
        } else {
            stop();
        }
    }, [currentStep, isFreePlan, start, stop]);

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
            case 'promise':
                return <StepPromise onContinue={goNext} />;

            case 'goal':
                return (
                    <StepGoal
                        value={answers.goal}
                        onSelect={(value) => saveAnswer('goal', value)}
                        onContinue={goNext}
                    />
                );

            case 'today':
                return (
                    <StepToday
                        value={answers.today}
                        onSelect={(value) => saveAnswer('today', value)}
                        onContinue={goNext}
                    />
                );

            case 'guess':
                return (
                    <StepGuess
                        currencyCode={auth.user.currency_code}
                        value={answers.spending_guess}
                        onContinue={(spendingGuess) => {
                            saveAnswer('spending_guess', spendingGuess);
                            goNext();
                        }}
                    />
                );

            case 'plan':
                return (
                    <StepPlan
                        goal={goalLabel(answers.goal)}
                        spendingGuess={answers.spending_guess}
                        currencyCode={auth.user.currency_code}
                        onContinue={goNext}
                    />
                );

            case 'create-account':
                return (
                    <StepAccountsHub
                        key={createdAccounts.length}
                        banks={banks}
                        isFirstAccount={isFirstAccount}
                        existingAccounts={accounts}
                        createdAccounts={createdAccounts}
                        hasSelectedConnectedAccount={
                            hasSelectedConnectedAccount
                        }
                        pendingMapping={pendingMapping}
                        onAccountCreated={handleAccountCreated}
                        onConnectedAccountSelected={
                            markConnectedAccountSelected
                        }
                        signupPlan={signupPlan}
                        onContinue={() => goToStep('syncing')}
                    />
                );

            case 'syncing':
                return <StepSyncing onComplete={() => goToStep('reveal')} />;

            case 'reveal':
                return (
                    <StepReveal
                        spendingGuess={answers.spending_guess}
                        onContinue={goNext}
                        onAddAccount={() => goToStep('create-account')}
                    />
                );

            case 'ai-suggestions':
                return (
                    <StepAiSuggestions
                        categories={categories}
                        hasConnectedAccount={hasConnectedAccount}
                        onAddAccount={() => goToStep('create-account')}
                        onComplete={goNext}
                    />
                );

            case 'import-transactions':
                return (
                    <StepImportTransactions
                        account={lastAccount}
                        canConnectBank={!isFreePlan}
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

            case 'target':
                return (
                    <StepTarget
                        goal={answers.goal}
                        spendingGuess={answers.spending_guess}
                        onContinue={goNext}
                    />
                );

            case 'complete':
                return (
                    <StepComplete
                        accountsCreated={createdAccounts.length}
                        hasConnectedAccount={hasConnectedAccount}
                        signupPlan={signupPlan}
                        spendingGuess={answers.spending_guess}
                    />
                );

            default:
                return null;
        }
    };

    const getStepTitle = (step: OnboardingStep): string => {
        const titles: Record<OnboardingStep, string> = {
            promise: __('Welcome'),
            goal: __('Your Goal'),
            today: __('How You Track'),
            guess: __('Your Guess'),
            plan: __('Your Plan'),
            'create-account': __('Create Account'),
            syncing: __('Syncing'),
            reveal: __('Last Month'),
            'ai-suggestions': __('AI Suggestions'),
            'import-transactions': __('Import Transactions'),
            'import-balances': __('Set Balance'),
            'categorize-transactions': __('Categorize Transactions'),
            target: __('Your First Target'),
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
                onBack={
                    BACKABLE_STEPS.includes(currentStep) ? goBack : undefined
                }
            >
                {renderStep()}
            </OnboardingLayout>
        </>
    );
}
