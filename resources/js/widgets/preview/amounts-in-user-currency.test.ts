import { describe, expect, it } from 'vitest';
import { useAmountsInUserCurrency } from './amounts-in-user-currency';
import { setPreviewPage } from './inertia-page';

describe('the preview stand-in for useAmountsInUserCurrency', () => {
    it('keeps amounts in the user currency and leaves the rest without a rate', () => {
        setPreviewPage({ locale: 'es-ES', currency: 'EUR' });

        // Not a React hook here: it reads a plain module, never React state.
        const amountInUserCurrency = useAmountsInUserCurrency();
        const day = '2026-10-01';

        expect(
            amountInUserCurrency({
                amount: -1250,
                currency_code: 'eur',
                transaction_date: day,
            }),
        ).toBe(-1250);
        expect(
            amountInUserCurrency({
                amount: -1250,
                currency_code: 'USD',
                transaction_date: day,
            }),
        ).toBeNull();
    });
});
