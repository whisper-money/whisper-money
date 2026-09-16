import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    rememberConnectCountry,
    useConnectFlow,
    useGuessedCountry,
} from './use-connect-flow';

const props = { locale: 'en-GB' };

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props }),
}));

vi.mock('@/lib/csrf', () => ({ getCsrfToken: () => 'token' }));

/** The bank list `/open-banking/institutions` hands back for a country. */
function institutions(banks: { name: string }[]) {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => banks,
        }),
    );
}

describe('useGuessedCountry', () => {
    beforeEach(() => {
        localStorage.clear();
        props.locale = 'en-GB';
    });

    it('guesses from the region the reader formats their money in', () => {
        props.locale = 'es-ES';

        expect(renderHook(() => useGuessedCountry()).result.current).toBe('ES');
    });

    it('offers nothing for a region we do not connect in', () => {
        props.locale = 'en-US';

        expect(renderHook(() => useGuessedCountry()).result.current).toBeNull();
    });

    /*
     * A Spaniard reading the app in English was guessed into the United Kingdom
     * — which has no banks behind it — and then guessed into it again on the
     * next attempt, and the one after that.
     */
    it('prefers the country the reader picked themselves last time', () => {
        rememberConnectCountry('ES');

        expect(renderHook(() => useGuessedCountry()).result.current).toBe('ES');
    });

    it('ignores a remembered country we no longer connect in', () => {
        localStorage.setItem('connect:country', 'ZZ');

        expect(renderHook(() => useGuessedCountry()).result.current).toBe('GB');
    });
});

describe('useConnectFlow', () => {
    beforeEach(() => {
        localStorage.clear();
        // Each case installs its own bank list; a leftover one from the case
        // before decides the outcome of this one otherwise.
        vi.unstubAllGlobals();
    });

    it('opens on the bank list when the guessed country has banks in it', async () => {
        institutions([{ name: 'BBVA' }]);

        const { result } = renderHook(() =>
            useConnectFlow([], {
                initialCountry: 'ES',
                separateProviders: true,
            }),
        );

        await waitFor(() =>
            expect(result.current.institutions).toHaveLength(1),
        );
        expect(result.current.step).toBe('bank');
    });

    /*
     * "No banks found" was the first screen after paying to connect one. A
     * guess that lands on an empty country is a guess that did not pay off, so
     * the flow asks for the country instead of showing the reader nothing.
     */
    it('asks for the country when the guess lands somewhere with no banks', async () => {
        institutions([]);

        // The onboarding's own options: brokers get a section of their own
        // there, so an empty country really does leave nothing on the screen.
        const { result } = renderHook(() =>
            useConnectFlow([], {
                initialCountry: 'GB',
                separateProviders: true,
            }),
        );

        await waitFor(() => expect(result.current.step).toBe('country'));
    });

    it('leaves an empty list the reader asked for on the bank step', async () => {
        institutions([{ name: 'BBVA' }]);

        const { result } = renderHook(() =>
            useConnectFlow([], { separateProviders: true }),
        );

        expect(result.current.step).toBe('country');

        institutions([]);

        await act(async () => {
            await result.current.fetchInstitutions('GB');
        });

        // Their own choice, so the answer — however empty — is the one they
        // asked for rather than a guess to walk back.
        expect(result.current.step).toBe('bank');
    });

    it('leaves a failed request where the reader is, with its own error', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        const { result } = renderHook(() =>
            useConnectFlow([], {
                initialCountry: 'ES',
                separateProviders: true,
            }),
        );

        await waitFor(() => expect(result.current.error).not.toBeNull());

        // Not the country list: nothing was learned about the country, and the
        // step the reader is on is the one showing them what went wrong.
        expect(result.current.step).toBe('bank');
    });
});
