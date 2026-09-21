import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { AccountForm } from './account-form';

const { pageProps } = vi.hoisted(() => ({
    pageProps: {
        current: {
            auth: { user: { currency_code: 'EUR' } },
            currencies: {
                accounts: [
                    { code: 'EUR', name: 'Euro' },
                    { code: 'USD', name: 'US Dollar' },
                ],
                profile: [{ code: 'EUR', name: 'Euro' }],
            },
        } as Record<string, unknown>,
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps.current }),
}));

vi.mock('./bank-combobox', () => ({
    BankCombobox: ({ disabled }: { disabled?: boolean }) => (
        <div data-testid="bank-combobox" data-disabled={String(disabled)} />
    ),
}));

const initialValues = {
    displayName: 'Checking',
    bank: null,
    type: 'checking' as const,
    currencyCode: 'EUR',
};

// Radix renders the trigger's selected value into a portal, so it has no
// accessible name in jsdom; the field's form name is what identifies it.
const currencySelect = (container: HTMLElement) =>
    container.querySelector('[name="currency_code"]');

describe('AccountForm', () => {
    it('locks the currency and the bank of a connected account', () => {
        const { container } = render(
            <AccountForm
                initialValues={initialValues}
                isConnected
                onChange={() => {}}
            />,
        );

        expect(currencySelect(container)).toBeDisabled();
        expect(
            screen.getByText(
                'Your bank sets the currency of a connected account.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByTestId('bank-combobox')).toHaveAttribute(
            'data-disabled',
            'true',
        );
        expect(
            screen.getByText(
                'A connected account keeps the bank of its connection.',
            ),
        ).toBeInTheDocument();
    });

    it('leaves the currency and the bank open on a manual account', () => {
        const { container } = render(
            <AccountForm initialValues={initialValues} onChange={() => {}} />,
        );

        expect(currencySelect(container)).toBeEnabled();
        expect(screen.getByTestId('bank-combobox')).toHaveAttribute(
            'data-disabled',
            'false',
        );
        expect(
            screen.getByText(
                'Leave empty for cash or any account without a bank.',
            ),
        ).toBeInTheDocument();
    });

    /**
     * Every account is worth something today, including the current account the
     * onboarding opens on. Asking only the types that move as a single number
     * landed people on a dashboard reading €0.00 one screen after being told it
     * would not be empty.
     */
    it('asks what a current account is worth today', () => {
        render(<AccountForm forceAccountType="checking" onChange={() => {}} />);

        expect(screen.getByLabelText('Balance')).toBeInTheDocument();
    });

    it('hands the balance of a current account to its caller', () => {
        const onChange = vi.fn();

        render(<AccountForm forceAccountType="checking" onChange={onChange} />);

        const balance = screen.getByLabelText('Balance');

        // `AmountInput` commits on blur rather than per keystroke, which is the
        // moment the form hears about it.
        fireEvent.change(balance, { target: { value: '2500' } });
        fireEvent.blur(balance);

        expect(onChange).toHaveBeenLastCalledWith(
            expect.objectContaining({ type: 'checking', balance: 250000 }),
        );
    });

    /**
     * What a fund is worth says nothing on its own: without what went in there
     * is no gain to read on the account page.
     */
    it('asks an investment account what has been put into it', () => {
        const onChange = vi.fn();

        render(
            <AccountForm forceAccountType="investment" onChange={onChange} />,
        );

        const invested = screen.getByLabelText('Invested amount');

        fireEvent.change(invested, { target: { value: '1200' } });
        fireEvent.blur(invested);

        expect(onChange).toHaveBeenLastCalledWith(
            expect.objectContaining({
                type: 'investment',
                investedAmount: 120000,
            }),
        );
    });

    it('does not ask a current account what has been put into it', () => {
        render(<AccountForm forceAccountType="checking" onChange={() => {}} />);

        expect(
            screen.queryByLabelText('Invested amount'),
        ).not.toBeInTheDocument();
    });

    it('keeps a loan asking for what is owed rather than a balance', () => {
        render(<AccountForm forceAccountType="loan" onChange={() => {}} />);

        expect(screen.getByLabelText('Owed Amount')).toBeInTheDocument();
    });

    // The reader's own currency was already on their profile; the select opened
    // empty anyway, on every account they added.
    it('opens on the currency the reader already keeps their money in', () => {
        const onChange = vi.fn();

        render(<AccountForm forceAccountType="checking" onChange={onChange} />);

        expect(onChange).toHaveBeenLastCalledWith(
            expect.objectContaining({ currencyCode: 'EUR' }),
        );
    });

    it('leaves the currency unpicked when the list does not offer the reader theirs', () => {
        pageProps.current = {
            ...pageProps.current,
            auth: { user: { currency_code: 'ZWL' } },
        };

        const onChange = vi.fn();

        render(<AccountForm forceAccountType="checking" onChange={onChange} />);

        expect(onChange).toHaveBeenLastCalledWith(
            expect.objectContaining({ currencyCode: null }),
        );
    });
});
