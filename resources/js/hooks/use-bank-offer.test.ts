import { rememberConnectCountry } from '@/hooks/use-connect-flow';
import { type SignupPlan } from '@/types/pricing';
import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useBankOffer } from './use-bank-offer';

const props = vi.hoisted(() => ({ openBankingEnabled: true }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props }),
}));

function bankOffered(
    signupPlan: SignupPlan | null,
    timezoneCountry: string | null,
): boolean {
    return renderHook(() => useBankOffer(signupPlan, timezoneCountry)).result
        .current.bankOffered;
}

describe('useBankOffer', () => {
    beforeEach(() => {
        localStorage.clear();
        props.openBankingEnabled = true;
    });

    it.each([
        ['a connectable country', 'ES'],
        ['an unknown country', null],
    ])('offers a bank in %s', (_, country) => {
        expect(bankOffered(null, country)).toBe(true);
    });

    it('offers none on the free plan', () => {
        expect(bankOffered('free', 'ES')).toBe(false);
    });

    it('offers none where no bank of ours reaches', () => {
        const { result } = renderHook(() => useBankOffer(null, 'MX'));

        expect(result.current.bankOffered).toBe(false);
        expect(result.current.bankHiddenByCountry).toBe(true);
    });

    it('offers none when open banking is off', () => {
        props.openBankingEnabled = false;

        const { result } = renderHook(() => useBankOffer(null, 'ES'));

        expect(result.current.bankOffered).toBe(false);
        // The country is not the reason, so it is not reported as one.
        expect(result.current.bankHiddenByCountry).toBe(false);
    });

    it('offers one again for a country picked by hand before', () => {
        rememberConnectCountry('ES');

        expect(bankOffered(null, 'MX')).toBe(true);
    });

    // The hub's bank picker remembers a country picked by hand; the screens
    // after the hub must see the bank back on offer.
    it('reads the country again when the read key changes', () => {
        const { result, rerender } = renderHook(
            ({ step }) => useBankOffer(null, 'MX', step),
            { initialProps: { step: 'create-account' } },
        );

        expect(result.current.bankOffered).toBe(false);

        rememberConnectCountry('ES');
        rerender({ step: 'create-account' });
        expect(result.current.bankOffered).toBe(false);

        rerender({ step: 'reveal' });
        expect(result.current.bankOffered).toBe(true);
    });
});
