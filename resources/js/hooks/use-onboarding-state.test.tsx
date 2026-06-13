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
