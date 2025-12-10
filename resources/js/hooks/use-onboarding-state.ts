import { useCallback, useState } from 'react';

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

const STEP_ORDER: OnboardingStep[] = [
    'welcome',
    'encryption-explained',
    'encryption-setup',
    'account-types',
    'create-account',
    'category-types',
    'customize-categories',
    'smart-rules',
    'import-transactions',
    'more-accounts',
    'complete',
];

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

export function useOnboardingState() {
    const [currentStep, setCurrentStep] = useState<OnboardingStep>('welcome');
    const [createdAccounts, setCreatedAccounts] = useState<CreatedAccount[]>(
        [],
    );

    const stepIndex = STEP_ORDER.indexOf(currentStep);
    const totalSteps = STEP_ORDER.length;

    const goToStep = useCallback((step: OnboardingStep) => {
        setCurrentStep(step);
    }, []);

    const goNext = useCallback(() => {
        const currentIndex = STEP_ORDER.indexOf(currentStep);
        if (currentIndex < STEP_ORDER.length - 1) {
            setCurrentStep(STEP_ORDER[currentIndex + 1]);
        }
    }, [currentStep]);

    const goBack = useCallback(() => {
        const currentIndex = STEP_ORDER.indexOf(currentStep);
        if (currentIndex > 0) {
            setCurrentStep(STEP_ORDER[currentIndex - 1]);
        }
    }, [currentStep]);

    const addCreatedAccount = useCallback((account: CreatedAccount) => {
        setCreatedAccounts((prev) => [...prev, account]);
    }, []);

    const isFirstAccount = createdAccounts.length === 0;

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
    };
}
