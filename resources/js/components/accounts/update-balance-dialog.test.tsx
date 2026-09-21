import type { Account } from '@/types/account';
import { render, waitFor } from '@testing-library/react';
import {
    afterAll,
    afterEach,
    beforeAll,
    describe,
    expect,
    it,
    vi,
} from 'vitest';
import { UpdateBalanceDialog } from './update-balance-dialog';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'en-US', auth: { user: { currency_code: 'USD' } } },
    }),
}));

const account = {
    id: 'acc-1',
    name: 'Savings',
    type: 'savings',
    currency_code: 'GBP',
} as Account;

/** The symbol `AmountInput` draws beside the field, e.g. `£`. */
function currencySymbolOf(inputId: string): string | undefined {
    const input = document.querySelector(inputId);

    return (
        input?.closest('div')?.querySelector('span')?.textContent ?? undefined
    );
}

describe('UpdateBalanceDialog', () => {
    const originalTimeZone = process.env.TZ;

    beforeAll(() => {
        // Argentina, where the reported bug lives.
        process.env.TZ = 'America/Argentina/Buenos_Aires';
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({ ok: true, json: async () => ({ data: [] }) })),
        );
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    afterAll(() => {
        process.env.TZ = originalTimeZone;
        vi.unstubAllGlobals();
    });

    // Opening the dialog reset the date through a second helper that a refactor
    // removed, which crashed every entry point into it in every time zone.
    it('opens and offers the local day, not the UTC one', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        vi.setSystemTime(new Date('2026-09-09T23:30:00'));

        render(
            <UpdateBalanceDialog
                account={account}
                open={true}
                onOpenChange={() => {}}
            />,
        );

        await waitFor(() => {
            expect(document.querySelector('#balance-date')).toHaveValue(
                '2026-09-09',
            );
        });
    });

    // invested_amount is stored in the account's currency, like balance. The
    // input used to be labelled with the user's, inviting a figure in the wrong
    // one straight into the database.
    it('labels the invested amount in the account currency, not the user one', async () => {
        render(
            <UpdateBalanceDialog
                account={account}
                open={true}
                onOpenChange={() => {}}
            />,
        );

        await waitFor(() => {
            expect(document.querySelector('#invested-amount')).toBeTruthy();
        });

        expect(currencySymbolOf('#invested-amount')).toBe('£');
        expect(currencySymbolOf('#balance-amount')).toBe('£');
    });
});
