import {
    readStoredValue,
    removeStoredValue,
    writeStoredValue,
} from '@/lib/safe-storage';
import { type AccountType } from '@/types/account';
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

/**
 * Every step onboarding may be resumed on, whether from ?step=, from the server
 * prop or from the value stored below. Mirrors `OnboardingController::VALID_STEPS`.
 */
export const VALID_STEPS: OnboardingStep[] = [
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

/**
 * The step is otherwise only ever persisted into ?step=, and a bank redirect
 * that dies on iOS drops the user back on a bare /onboarding — which restarted
 * them from 'welcome' having already created their accounts. localStorage
 * survives that round trip; the URL does not.
 */
const STEP_STORAGE_KEY = 'onboarding-step';

/**
 * Forget the resume point. Called once onboarding is actually finished, so a
 * returning user is not pulled back into it.
 */
export function clearStoredOnboardingStep(): void {
    removeStoredValue(STEP_STORAGE_KEY);
}

/**
 * The stored resume point, or undefined when there is none worth trusting. A
 * stale or hand-edited key is validated like any deep link, so it cannot drop a
 * free-plan user onto the AI step they never see.
 */
function readStoredStep(
    skipAiSuggestions: boolean,
): OnboardingStep | undefined {
    const stored = readStoredValue(STEP_STORAGE_KEY) as OnboardingStep | null;

    if (!stored || !VALID_STEPS.includes(stored)) {
        return undefined;
    }

    return skipAiSuggestions && stored === 'ai-suggestions'
        ? undefined
        : stored;
}

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

/**
 * Steps the header's back arrow is offered on. Everything else is left out on
 * purpose: 'welcome' has nowhere to go; 'create-account' carries its own back
 * buttons inside the bank flow and the manual form; backing out of 'syncing',
 * 'ai-suggestions' or 'complete' would abandon work already in flight; and
 * 'customize-categories' is not in PRIMARY_STEPS, so goBack() there is a no-op.
 */
export const BACKABLE_STEPS: OnboardingStep[] = [
    'account-types',
    'category-types',
    'smart-rules',
    'import-transactions',
    'import-balances',
];

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
    type: AccountType;
    currencyCode: string;
    bankName?: string;
    bankLogo?: string | null;
    connected?: boolean;
}

interface UseOnboardingStateOptions {
    existingAccountsCount?: number;
    initialStep?: OnboardingStep;
    hasConnectedAccount?: boolean;
    skipAiSuggestions?: boolean;
}

export function useOnboardingState(options: UseOnboardingStateOptions = {}) {
    const {
        existingAccountsCount = 0,
        initialStep,
        hasConnectedAccount = false,
        skipAiSuggestions = false,
    } = options;

    // Dropped from the array rather than short-circuited in the component, so
    // the progress counter and goNext() agree on how many steps there are.
    const primarySteps = useMemo(
        () =>
            skipAiSuggestions
                ? PRIMARY_STEPS.filter((step) => step !== 'ai-suggestions')
                : PRIMARY_STEPS,
        [skipAiSuggestions],
    );

    // Determine initial step based on existing state. The server prop (already
    // covering ?step=) wins; the stored step only answers a return with neither.
    const resolvedInitialStep = useMemo((): OnboardingStep => {
        return initialStep ?? readStoredStep(skipAiSuggestions) ?? 'welcome';
    }, [initialStep, skipAiSuggestions]);

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
        writeStoredValue(STEP_STORAGE_KEY, currentStep);
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
