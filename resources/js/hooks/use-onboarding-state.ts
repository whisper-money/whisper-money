import { getStoredKey } from '@/lib/key-storage';
import { useCallback, useMemo, useState } from 'react';

export type OnboardingStep =
    | 'welcome'
    | 'encryption-explained'
    | 'encryption-setup'
    | 'account-types'
    | 'create-account'
    | 'category-types'
    | 'customize-categories'
    | 'smart-rules'
    | 'import-transactions'
    | 'import-balances'
    | 'more-accounts'
    | 'complete';

// Primary steps shown in the progress indicator
// import-transactions and import-balances are sub-steps that don't increment the counter
const PRIMARY_STEPS: OnboardingStep[] = [
    'welcome',
    'encryption-explained',
    'encryption-setup',
    'account-types',
    'create-account',
    'category-types',
    'customize-categories',
    'smart-rules',
    'more-accounts',
    'complete',
];

// Steps that are sub-steps (shown under the same progress position as 'create-account')
const SUB_STEPS: OnboardingStep[] = ['import-transactions', 'import-balances'];

const SKIPPABLE_ENCRYPTION_STEPS: OnboardingStep[] = ['encryption-setup'];

export interface OnboardingState {
    currentStep: OnboardingStep;
    stepIndex: number;
    totalSteps: number;
    createdAccounts: CreatedAccount[];
    isFirstAccount: boolean;
}

export interface CreatedAccount {
    id: string;
    name: string;
    type: string;
    currencyCode: string;
}

interface UseOnboardingStateOptions {
    existingAccountsCount?: number;
    hasEncryptionSetup?: boolean;
}

export function useOnboardingState(options: UseOnboardingStateOptions = {}) {
    const { existingAccountsCount = 0, hasEncryptionSetup = false } = options;

    // Check both: backend says encryption is set up AND we have the key in browser storage
    // We need the key in storage to actually decrypt data
    const hasEncryptionKey = useMemo(() => {
        // If backend says encryption is not set up, we definitely don't have a key
        if (!hasEncryptionSetup) {
            return false;
        }
        // If backend says it's set up, also check if we have the key locally
        return getStoredKey() !== null;
    }, [hasEncryptionSetup]);

    const primarySteps = useMemo(() => {
        if (hasEncryptionKey) {
            return PRIMARY_STEPS.filter(
                (step) => !SKIPPABLE_ENCRYPTION_STEPS.includes(step),
            );
        }
        return PRIMARY_STEPS;
    }, [hasEncryptionKey]);

    // Determine initial step based on existing state
    const initialStep = useMemo((): OnboardingStep => {
        return 'welcome';
    }, []);

    const [currentStep, setCurrentStep] = useState<OnboardingStep>(initialStep);
    const [createdAccounts, setCreatedAccounts] = useState<CreatedAccount[]>(
        [],
    );

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

    const isFirstAccount =
        createdAccounts.length === 0 && existingAccountsCount === 0;

    return {
        currentStep,
        stepIndex,
        totalSteps,
        createdAccounts,
        isFirstAccount,
        goToStep,
        goNext,
        goBack,
        addCreatedAccount,
        hasEncryptionKey,
    };
}
