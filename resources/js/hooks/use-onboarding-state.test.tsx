import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useOnboardingState } from './use-onboarding-state';

const { captureEvent, post } = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@inertiajs/react', () => ({ router: { post } }));

describe('useOnboardingState', () => {
    describe('step URL sync', () => {
        beforeEach(() => {
            window.history.replaceState(null, '', '/onboarding');
        });

        afterEach(() => {
            window.history.replaceState(null, '', '/onboarding');
        });

        it('reflects the current step in the ?step= query param', () => {
            renderHook(() => useOnboardingState());

            expect(
                new URLSearchParams(window.location.search).get('step'),
            ).toBe('promise');
        });

        it('updates the ?step= query param when the step advances', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.goNext();
            });

            expect(
                new URLSearchParams(window.location.search).get('step'),
            ).toBe('goal');
        });

        it('reflects a step reached via goToStep', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.goToStep('import-balances');
            });

            expect(
                new URLSearchParams(window.location.search).get('step'),
            ).toBe('import-balances');
        });

        it('preserves other query params when syncing the step', () => {
            window.history.replaceState(null, '', '/onboarding?ref=email');

            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.goToStep('syncing');
            });

            const params = new URLSearchParams(window.location.search);
            expect(params.get('step')).toBe('syncing');
            expect(params.get('ref')).toBe('email');
        });
    });

    it('tracks when connected account setup has been selected', () => {
        const { result } = renderHook(() => useOnboardingState());

        expect(result.current.hasSelectedConnectedAccount).toBe(false);

        act(() => {
            result.current.markConnectedAccountSelected();
        });

        expect(result.current.hasSelectedConnectedAccount).toBe(true);
    });

    it('starts with connected account setup selected when a connected account already exists', () => {
        const { result } = renderHook(() =>
            useOnboardingState({ hasConnectedAccount: true }),
        );

        expect(result.current.hasSelectedConnectedAccount).toBe(true);
    });

    // The reveal answers the guess step 4 took, so it has to land between the
    // import finishing and anything being asked of the user again.
    it('puts the reveal after the accounts hub, then the AI step', () => {
        const { result } = renderHook(() => useOnboardingState());

        act(() => {
            result.current.goToStep('create-account');
        });
        act(() => {
            result.current.goNext();
        });

        expect(result.current.currentStep).toBe('reveal');

        act(() => {
            result.current.goNext();
        });

        expect(result.current.currentStep).toBe('ai-suggestions');
    });

    // Waiting on a bank's first sync is part of connecting it, so the bar must
    // not move for it — the hub hands over to it and on to the reveal itself.
    it('holds the sync at the accounts hub position', () => {
        const { result } = renderHook(() => useOnboardingState());
        const hub = renderHook(() => useOnboardingState());

        act(() => {
            hub.result.current.goToStep('create-account');
        });
        act(() => {
            result.current.goToStep('syncing');
        });

        expect(result.current.stepIndex).toBe(hub.result.current.stepIndex);
    });

    // The redesign ends at eleven, and the list is now the only thing saying so.
    it('closes on the target and the inventory', () => {
        const { result } = renderHook(() => useOnboardingState());

        act(() => {
            result.current.goToStep('categorize-transactions');
        });
        act(() => {
            result.current.goNext();
        });

        expect(result.current.currentStep).toBe('target');
        expect(result.current.stepIndex).toBe(9);

        act(() => {
            result.current.goNext();
        });

        expect(result.current.currentStep).toBe('complete');
        expect(result.current.stepIndex + 1).toBe(result.current.totalSteps);
    });

    describe('skipping the AI step for a free signup', () => {
        it('walks from the reveal straight to categorize-transactions', () => {
            const { result } = renderHook(() =>
                useOnboardingState({ skipAiSuggestions: true }),
            );

            act(() => {
                result.current.goToStep('reveal');
            });
            act(() => {
                result.current.goNext();
            });

            expect(result.current.currentStep).toBe('categorize-transactions');
        });

        it('drops the AI step from the progress counter', () => {
            const { result: free } = renderHook(() =>
                useOnboardingState({ skipAiSuggestions: true }),
            );
            const { result: standard } = renderHook(() => useOnboardingState());

            expect(free.current.totalSteps).toBe(
                standard.current.totalSteps - 1,
            );
        });

        it('keeps the AI step for every other signup', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.goToStep('reveal');
            });
            act(() => {
                result.current.goNext();
            });

            expect(result.current.currentStep).toBe('ai-suggestions');
        });
    });

    describe('resuming after a redirect that lost the ?step= param', () => {
        const store = (value: string) =>
            window.localStorage.setItem('onboarding-step', value);

        // iOS hands the user back to a bare /onboarding when a bank redirect
        // dies, and the step only ever lived in the URL.
        it('resumes from the stored step when nothing else says otherwise', () => {
            store('user-1:create-account');

            const { result } = renderHook(() =>
                useOnboardingState({ userId: 'user-1' }),
            );

            expect(result.current.currentStep).toBe('create-account');
        });

        it('lets the server-resolved step win over the stored one', () => {
            store('user-1:create-account');

            const { result } = renderHook(() =>
                useOnboardingState({
                    userId: 'user-1',
                    initialStep: 'syncing',
                }),
            );

            expect(result.current.currentStep).toBe('syncing');
        });

        it('falls back to the first step when the stored step is not real', () => {
            store('user-1:not-a-step');

            const { result } = renderHook(() =>
                useOnboardingState({ userId: 'user-1' }),
            );

            expect(result.current.currentStep).toBe('promise');
        });

        // The redesign renames the steps under a storage key that survives the
        // deploy, so anyone mid-onboarding when it ships has a step stored that
        // no longer exists. It has to read as "start over", not as a blank screen.
        it.each([
            'welcome',
            'account-types',
            'category-types',
            'customize-categories',
            'smart-rules',
        ])('falls back to the first step from the retired %s', (retired) => {
            store(`user-1:${retired}`);

            const { result } = renderHook(() =>
                useOnboardingState({ userId: 'user-1' }),
            );

            expect(result.current.currentStep).toBe('promise');
        });

        // The steps that outlived the rename still resume, so a user who had
        // already created their accounts is not sent back to the first question.
        it('still resumes a step the redesign kept', () => {
            store('user-1:create-account');

            const { result } = renderHook(() =>
                useOnboardingState({ userId: 'user-1' }),
            );

            expect(result.current.currentStep).toBe('create-account');
        });

        it('never resumes a free signup onto the AI step', () => {
            store('user-1:ai-suggestions');

            const { result } = renderHook(() =>
                useOnboardingState({
                    userId: 'user-1',
                    skipAiSuggestions: true,
                }),
            );

            expect(result.current.currentStep).toBe('promise');
        });

        // Storage is per browser, not per account: the next signup on a shared
        // machine would otherwise land mid-onboarding with nothing created.
        it('ignores a step another account left behind', () => {
            store('user-1:syncing');

            const { result } = renderHook(() =>
                useOnboardingState({ userId: 'user-2' }),
            );

            expect(result.current.currentStep).toBe('promise');
        });

        it('stores every step it moves to against its owner', () => {
            const { result } = renderHook(() =>
                useOnboardingState({ userId: 'user-1' }),
            );

            act(() => {
                result.current.goToStep('syncing');
            });

            expect(window.localStorage.getItem('onboarding-step')).toBe(
                'user-1:syncing',
            );
        });

        it('stores nothing when there is no user to store it against', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.goToStep('syncing');
            });

            expect(window.localStorage.getItem('onboarding-step')).toBeNull();
        });
    });

    describe('step analytics', () => {
        beforeEach(() => {
            captureEvent.mockClear();
        });

        const stepEvents = () =>
            captureEvent.mock.calls.filter(
                ([name]) => name === 'onboarding_step_viewed',
            );

        it('reports the step, its position and the signup plan', () => {
            renderHook(() =>
                useOnboardingState({ signupPlan: 'paid', userId: 'user-1' }),
            );

            expect(captureEvent).toHaveBeenCalledWith(
                'onboarding_step_viewed',
                {
                    $set: { onboarding_flow: 'benefits-first' },
                    step: 'promise',
                    step_index: 1,
                    total_steps: 11,
                    signup_plan: 'paid',
                    accounts_count: 0,
                    resumed: false,
                },
            );
        });

        /**
         * The old flow and this one share no step vocabulary, so nothing in the
         * events says which one a person saw once `welcome` and `promise` are
         * both in the table. The label goes on the person, so every onboarding
         * event they send afterwards carries it and one funnel can compare the
         * two.
         */
        it('labels the person with the flow they were shown', () => {
            renderHook(() => useOnboardingState({ userId: 'user-1' }));

            const [, properties] = captureEvent.mock.calls.find(
                ([name]) => name === 'onboarding_step_viewed',
            )!;

            expect(properties.$set).toEqual({
                onboarding_flow: 'benefits-first',
            });
        });

        // The accounts hub is one step with two very different screens, and
        // this is the only thing on the event that says which one was seen.
        it('reports what the user has to show for themselves so far', () => {
            renderHook(() =>
                useOnboardingState({
                    initialStep: 'create-account',
                    existingAccountsCount: 3,
                }),
            );

            expect(stepEvents()[0][1]).toMatchObject({
                step: 'create-account',
                accounts_count: 3,
            });
        });

        it('counts the steps a free signup actually sees', () => {
            renderHook(() =>
                useOnboardingState({
                    signupPlan: 'free',
                    skipAiSuggestions: true,
                }),
            );

            expect(stepEvents()[0][1]).toMatchObject({
                total_steps: 10,
                signup_plan: 'free',
            });
        });

        // The whole point of the hook-level event: one per step, however many
        // times React re-renders it. StrictMode double-invokes effects in dev.
        it('reports a step once, however often the hook re-renders', () => {
            const { rerender } = renderHook(() => useOnboardingState());

            rerender();
            rerender();

            expect(stepEvents()).toHaveLength(1);
        });

        it('reports each step the user moves to', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.goNext();
            });

            expect(stepEvents().map(([, props]) => props)).toMatchObject([
                { step: 'promise', step_index: 1 },
                { step: 'goal', step_index: 2 },
            ]);
        });

        it('reports a sub-step under the position it shares with create-account', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.goToStep('import-transactions');
            });

            expect(stepEvents().at(-1)?.[1]).toMatchObject({
                step: 'import-transactions',
                step_index: 6,
            });
        });

        it('flags an entry that resumed mid-flow', () => {
            const { result } = renderHook(() =>
                useOnboardingState({ initialStep: 'reveal' }),
            );

            expect(stepEvents()[0][1]).toMatchObject({
                step: 'reveal',
                resumed: true,
            });

            // Only the entry itself is a resume; what follows is normal progress.
            act(() => {
                result.current.goNext();
            });

            expect(stepEvents().at(-1)?.[1]).toMatchObject({
                step: 'ai-suggestions',
                resumed: false,
            });
        });

        it('flags a resume from the stored step too', () => {
            window.localStorage.setItem('onboarding-step', 'user-1:syncing');

            renderHook(() => useOnboardingState({ userId: 'user-1' }));

            expect(stepEvents()[0][1]).toMatchObject({
                step: 'syncing',
                resumed: true,
            });
        });
    });

    describe('the answers the questions collect', () => {
        beforeEach(() => {
            captureEvent.mockClear();
            post.mockClear();
        });

        it('keeps an answer for the steps that read it back', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.saveAnswer('goal', 'understand');
            });

            expect(result.current.answers).toEqual({ goal: 'understand' });
        });

        it('starts from the answers already on the user row', () => {
            const { result } = renderHook(() =>
                useOnboardingState({
                    initialAnswers: { goal: 'debt', spending_guess: 120000 },
                }),
            );

            expect(result.current.answers.spending_guess).toBe(120000);
        });

        // Each answer is written as it is given, so quitting on the third
        // question still leaves the first two behind.
        it('sends each answer on its own without disturbing the wizard', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.saveAnswer('spending_guess', 120000);
            });

            expect(post).toHaveBeenCalledWith(
                '/onboarding/answers',
                { spending_guess: 120000 },
                expect.objectContaining({
                    preserveState: true,
                    only: ['onboardingAnswers'],
                }),
            );
        });

        it('merges a later answer into the ones already given', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.saveAnswer('goal', 'understand');
            });
            act(() => {
                result.current.saveAnswer('today', 'spreadsheet');
            });

            expect(result.current.answers).toEqual({
                goal: 'understand',
                today: 'spreadsheet',
            });
        });

        it('reports the answer to analytics', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.saveAnswer('today', 'head');
            });

            expect(captureEvent).toHaveBeenCalledWith('onboarding_answered', {
                question: 'today',
                answer: 'head',
            });
        });
    });

    it('remembers connected account setup when a connected account appears later', () => {
        const { result, rerender } = renderHook(
            ({ hasConnectedAccount }: { hasConnectedAccount: boolean }) =>
                useOnboardingState({ hasConnectedAccount }),
            {
                initialProps: { hasConnectedAccount: false },
            },
        );

        expect(result.current.hasSelectedConnectedAccount).toBe(false);

        rerender({ hasConnectedAccount: true });

        expect(result.current.hasSelectedConnectedAccount).toBe(true);
    });
});
