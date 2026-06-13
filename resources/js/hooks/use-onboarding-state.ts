import { useCallback, useEffect, useMemo, useState } from 'react';

export type OnboardingStep =
    | 'welcome'
    | 'account-types'
    | 'create-account'
    | 'category-types'
    | 'customize-categories'
    | 'smart-rules'
    | 'syncing'
    | 'ai-suggestions'
    | 'import-transactions'
    | 'import-balances'
    | 'categorize-transactions'
    | 'complete';

// Primary steps shown in the progress indicator
// import-transactions and import-balances are sub-steps that don't increment the counter
const PRIMARY_STEPS: OnboardingStep[] = [
    'welcome',
    'account-types',
    'create-account',
    'category-types',
    'smart-rules',
    'syncing',
    'ai-suggestions',
    'categorize-transactions',
    'complete',
];

// Steps that are sub-steps (shown under the same progress position as 'create-account')
const SUB_STEPS: OnboardingStep[] = ['import-transactions', 'import-balances'];

export interface OnboardingState {
    currentStep: OnboardingStep;
    stepIndex: number;
    totalSteps: number;
    createdAccounts: CreatedAccount[];
    isFirstAccount: boolean;
    hasSelectedConnectedAccount: boolean;
}

export interface CreatedAccount {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
    bankName?: string;
    bankLogo?: string | null;
    connected?: boolean;
}

interface UseOnboardingStateOptions {
    existingAccountsCount?: number;
    initialStep?: OnboardingStep;
    hasConnectedAccount?: boolean;
}

export function useOnboardingState(options: UseOnboardingStateOptions = {}) {
    const {
        existingAccountsCount = 0,
        initialStep,
        hasConnectedAccount = false,
    } = options;

    const primarySteps = PRIMARY_STEPS;

    // Determine initial step based on existing state
    const resolvedInitialStep = useMemo((): OnboardingStep => {
        return initialStep ?? 'welcome';
    }, [initialStep]);

    const [currentStep, setCurrentStep] =
        useState<OnboardingStep>(resolvedInitialStep);
    const [createdAccounts, setCreatedAccounts] = useState<CreatedAccount[]>(
        [],
    );
    const [hasSelectedConnectedAccount, setHasSelectedConnectedAccount] =
        useState(hasConnectedAccount);

    useEffect(() => {
        if (hasConnectedAccount) {
            setHasSelectedConnectedAccount(true);
        }
    }, [hasConnectedAccount]);

    // Keep the ?step= query param in sync with the current step so a manual
    // refresh returns the user to the step they were on. Use replaceState to
    // avoid polluting browser history and preserve Inertia's stored page state.
    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }
        const url = new URL(window.location.href);
        if (url.searchParams.get('step') === currentStep) {
            return;
        }
        url.searchParams.set('step', currentStep);
        window.history.replaceState(window.history.state, '', url.toString());
    }, [currentStep]);

    // Calculate step index for progress indicator
    // Sub-steps (import-transactions, import-balances) use the same index as 'create-account'
    const stepIndex = useMemo(() => {
        if (SUB_STEPS.includes(currentStep)) {
            // Sub-steps show under the 'create-account' position
            return primarySteps.indexOf('create-account');
        }
        return primarySteps.indexOf(currentStep);
    }, [currentStep, primarySteps]);

    const totalSteps = primarySteps.length;

    const goToStep = useCallback((step: OnboardingStep) => {
        setCurrentStep(step);
    }, []);

    const goNext = useCallback(() => {
        // Find the next primary step
        const primaryIndex = primarySteps.indexOf(currentStep);
        if (primaryIndex >= 0 && primaryIndex < primarySteps.length - 1) {
            setCurrentStep(primarySteps[primaryIndex + 1]);
        }
    }, [currentStep, primarySteps]);

    const goBack = useCallback(() => {
        // If we're on a sub-step, go back to create-account
        if (SUB_STEPS.includes(currentStep)) {
            setCurrentStep('create-account');
            return;
        }
        const primaryIndex = primarySteps.indexOf(currentStep);
        if (primaryIndex > 0) {
            setCurrentStep(primarySteps[primaryIndex - 1]);
        }
    }, [currentStep, primarySteps]);

    const addCreatedAccount = useCallback((account: CreatedAccount) => {
        setCreatedAccounts((prev) => [...prev, account]);
    }, []);

    const markConnectedAccountSelected = useCallback(() => {
        setHasSelectedConnectedAccount(true);
    }, []);

    const isFirstAccount =
        createdAccounts.length === 0 && existingAccountsCount === 0;

    return {
        currentStep,
        stepIndex,
        totalSteps,
        createdAccounts,
        isFirstAccount,
        hasSelectedConnectedAccount,
        goToStep,
        goNext,
        goBack,
        addCreatedAccount,
        markConnectedAccountSelected,
    };
}
