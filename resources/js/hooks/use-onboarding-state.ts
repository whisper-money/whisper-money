import { answers as storeAnswers } from '@/actions/App/Http/Controllers/OnboardingController';
import { captureEvent } from '@/lib/posthog';
import {
    readStoredValue,
    removeStoredValue,
    writeStoredValue,
} from '@/lib/safe-storage';
import { type AccountType } from '@/types/account';
import { type SignupPlan } from '@/types/pricing';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

export type OnboardingStep =
    | 'promise'
    | 'goal'
    | 'today'
    | 'guess'
    | 'plan'
    | 'create-account'
    | 'syncing'
    | 'reveal'
    | 'ai-suggestions'
    | 'import-transactions'
    | 'import-balances'
    | 'categorize-transactions'
    | 'target'
    | 'complete';

/**
 * Every step onboarding may be resumed on, whether from ?step=, from the server
 * prop or from the value stored below. Mirrors `OnboardingController::VALID_STEPS`.
 */
const VALID_STEPS: OnboardingStep[] = [
    'promise',
    'goal',
    'today',
    'guess',
    'plan',
    'create-account',
    'import-transactions',
    'import-balances',
    'syncing',
    'reveal',
    'ai-suggestions',
    'categorize-transactions',
    'target',
    'complete',
];

/**
 * The steps a signup may be resumed on. A free signup never sees the AI step, so
 * no resume path - deep link, stored value or otherwise - may land them on it.
 */
export function validStepsFor(skipAiSuggestions: boolean): OnboardingStep[] {
    return skipAiSuggestions
        ? VALID_STEPS.filter((step) => step !== 'ai-suggestions')
        : VALID_STEPS;
}

/**
 * The step is otherwise only ever persisted into ?step=, and a bank redirect
 * that dies on iOS drops the user back on a bare /onboarding — which restarted
 * them from the first step having already created their accounts. localStorage
 * survives that round trip; the URL does not.
 *
 * The value carries the user it belongs to, because storage is per browser and
 * not per account. Without that, the next person to sign up on a shared browser
 * resumes into someone else's progress, having created none of the accounts the
 * step they land on assumes.
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
 * The resume point stored for this user, or undefined when there is none worth
 * trusting: another account's, or a stale or hand-edited step, which is
 * validated like any deep link.
 */
function readStoredStep(
    userId: string | undefined,
    skipAiSuggestions: boolean,
): OnboardingStep | undefined {
    const [owner, stored] = (readStoredValue(STEP_STORAGE_KEY) ?? '').split(
        ':',
    );

    return userId !== undefined &&
        owner === userId &&
        validStepsFor(skipAiSuggestions).includes(stored as OnboardingStep)
        ? (stored as OnboardingStep)
        : undefined;
}

/**
 * What the progress bar is drawn over, and now the only thing it is measured
 * against: the redesign is complete, so the list is the flow rather than a
 * count the flow is working towards.
 */
const PRIMARY_STEPS: OnboardingStep[] = [
    'promise',
    'goal',
    'today',
    'guess',
    'plan',
    'create-account',
    'reveal',
    'ai-suggestions',
    'categorize-transactions',
    'target',
    'complete',
];

/** Where onboarding starts, and where anything unresolvable falls back to. */
const FIRST_STEP: OnboardingStep = PRIMARY_STEPS[0];

/**
 * Steps shown under the same progress position as 'create-account'. Waiting on
 * a bank's first sync is part of connecting it, not a step of its own: the bar
 * would otherwise move for something the user did not do.
 */
const SUB_STEPS: OnboardingStep[] = [
    'import-transactions',
    'import-balances',
    'syncing',
];

/**
 * Steps the header's back arrow is offered on. Everything else is left out on
 * purpose: 'promise' has nowhere to go; 'create-account' carries its own back
 * buttons inside the bank flow and the manual form; and backing out of
 * 'syncing', 'ai-suggestions' or 'complete' would abandon work already in
 * flight.
 */
export const BACKABLE_STEPS: OnboardingStep[] = [
    'goal',
    'today',
    'guess',
    'plan',
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

/**
 * What the user told the onboarding about themselves. The first two are kept
 * because the question was asked anyway; the last two are read back by the
 * screens that close the flow. Mirrors `StoreOnboardingAnswersRequest::CHOICES`,
 * except for the target, which step 10 writes through its own endpoint once the
 * budget behind it exists.
 */
export interface OnboardingAnswers {
    goal?: string;
    today?: string;
    /** Minor units of the user's own currency, like every stored amount. */
    spending_guess?: number;
    /** Minor units. Written by step 10, and only once its budget exists. */
    target?: number;
}

export interface CreatedAccount {
    id: string;
    name: string;
    type: AccountType;
    currencyCode: string;
    bankName?: string;
    bankLogo?: string | null;
    connected?: boolean;
    /**
     * Whether the form already took a balance. A mortgage or a pension is a
     * balance and nothing else, so the step that asks for one has nothing left
     * to ask when the form got it — and asking twice reads as the first answer
     * not having landed.
     */
    hasBalance?: boolean;
}

interface UseOnboardingStateOptions {
    existingAccountsCount?: number;
    initialStep?: OnboardingStep;
    hasConnectedAccount?: boolean;
    skipAiSuggestions?: boolean;
    /** Owner of the stored resume point. Nothing is stored without it. */
    userId?: string;
    /** Reported on every step event: it decides how many steps there are. */
    signupPlan?: SignupPlan | null;
    /** Answers already on the user's row, so a reload keeps them. */
    initialAnswers?: OnboardingAnswers;
}

export function useOnboardingState(options: UseOnboardingStateOptions = {}) {
    const {
        existingAccountsCount = 0,
        initialStep,
        hasConnectedAccount = false,
        skipAiSuggestions = false,
        userId,
        signupPlan = null,
        initialAnswers = {},
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
        return (
            initialStep ??
            readStoredStep(userId, skipAiSuggestions) ??
            FIRST_STEP
        );
    }, [initialStep, skipAiSuggestions, userId]);

    const [currentStep, setCurrentStep] =
        useState<OnboardingStep>(resolvedInitialStep);
    const [createdAccounts, setCreatedAccounts] = useState<CreatedAccount[]>(
        [],
    );
    const [hasSelectedConnectedAccount, setHasSelectedConnectedAccount] =
        useState(hasConnectedAccount);
    const [answers, setAnswers] = useState<OnboardingAnswers>(initialAnswers);

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
        if (userId !== undefined) {
            writeStoredValue(STEP_STORAGE_KEY, `${userId}:${currentStep}`);
        }
        const url = new URL(window.location.href);
        if (url.searchParams.get('step') === currentStep) {
            return;
        }
        url.searchParams.set('step', currentStep);
        window.history.replaceState(window.history.state, '', url.toString());
    }, [currentStep, userId]);

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

    /**
     * Every step entry, from the one place that owns step transitions: a deep
     * link, a stored resume point and a bank redirect coming back all land here,
     * where a per-component effect would miss them. The wizard is a single
     * Inertia render, so autocapture sees one `$pageview` for all nine steps and
     * this event is the only thing a funnel can be built from.
     */
    const lastTrackedStep = useRef<OnboardingStep | null>(null);

    /**
     * What the user has to show for themselves so far. Reported on every step
     * so the accounts hub's two states — nothing in yet, and the list of what
     * is — can be told apart in the funnel: they are one step, and only this
     * separates someone who dropped out facing an empty screen from someone who
     * dropped out having already connected a bank.
     */
    const accountsCount = createdAccounts.length + existingAccountsCount;

    useEffect(() => {
        // The effect re-runs whenever the counter changes and React StrictMode
        // double-invokes it in development, so only a step that is actually new
        // counts as a view - otherwise every step ships doubled.
        if (lastTrackedStep.current === currentStep) {
            return;
        }

        const isEntryStep = lastTrackedStep.current === null;
        lastTrackedStep.current = currentStep;

        captureEvent('onboarding_step_viewed', {
            step: currentStep,
            // 1-based to read as "3 of 11". A step outside the progress
            // counter has no position to report.
            step_index: stepIndex >= 0 ? stepIndex + 1 : null,
            total_steps: totalSteps,
            signup_plan: signupPlan,
            accounts_count: accountsCount,
            // A reload or a return from the bank re-fires the step the user was
            // already on. Harmless for a funnel, which dedupes by person, and
            // ruinous for raw drop-off counts - so both readings stay available.
            resumed: isEntryStep && resolvedInitialStep !== FIRST_STEP,
        });
    }, [
        currentStep,
        stepIndex,
        totalSteps,
        signupPlan,
        accountsCount,
        resolvedInitialStep,
    ]);

    const goToStep = useCallback((step: OnboardingStep) => {
        setCurrentStep(step);
    }, []);

    /**
     * Record one answer, locally and on the user's row.
     *
     * Written as it is given rather than in one batch at the end, so a user who
     * quits on the third question still leaves the first two behind. The visit
     * asks for nothing back but the answers themselves and keeps the wizard's
     * state, so a request between two questions cannot reset the flow.
     */
    const saveAnswer = useCallback(
        <K extends keyof OnboardingAnswers>(
            question: K,
            answer: NonNullable<OnboardingAnswers[K]>,
        ) => {
            setAnswers((previous) => ({ ...previous, [question]: answer }));

            captureEvent('onboarding_answered', { question, answer });

            router.post(
                storeAnswers.url(),
                { [question]: answer },
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['onboardingAnswers'],
                },
            );
        },
        [],
    );

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
    };
}
