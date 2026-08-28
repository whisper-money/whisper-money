import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { useOnboardingState } from './use-onboarding-state';

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
            ).toBe('welcome');
        });

        it('updates the ?step= query param when the step advances', () => {
            const { result } = renderHook(() => useOnboardingState());

            act(() => {
                result.current.goNext();
            });

            expect(
                new URLSearchParams(window.location.search).get('step'),
            ).toBe('account-types');
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

    describe('skipping the AI step for a free signup', () => {
        it('walks from syncing straight to categorize-transactions', () => {
            const { result } = renderHook(() =>
                useOnboardingState({ skipAiSuggestions: true }),
            );

            act(() => {
                result.current.goToStep('syncing');
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
                result.current.goToStep('syncing');
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
                    initialStep: 'smart-rules',
                }),
            );

            expect(result.current.currentStep).toBe('smart-rules');
        });

        it('falls back to welcome when the stored step is not a real step', () => {
            store('user-1:not-a-step');

            const { result } = renderHook(() =>
                useOnboardingState({ userId: 'user-1' }),
            );

            expect(result.current.currentStep).toBe('welcome');
        });

        it('never resumes a free signup onto the AI step', () => {
            store('user-1:ai-suggestions');

            const { result } = renderHook(() =>
                useOnboardingState({
                    userId: 'user-1',
                    skipAiSuggestions: true,
                }),
            );

            expect(result.current.currentStep).toBe('welcome');
        });

        // Storage is per browser, not per account: the next signup on a shared
        // machine would otherwise land mid-onboarding with nothing created.
        it('ignores a step another account left behind', () => {
            store('user-1:smart-rules');

            const { result } = renderHook(() =>
                useOnboardingState({ userId: 'user-2' }),
            );

            expect(result.current.currentStep).toBe('welcome');
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
