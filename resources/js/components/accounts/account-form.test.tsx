import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { AccountForm } from './account-form';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            currencies: {
                accounts: [
                    { code: 'EUR', name: 'Euro' },
                    { code: 'USD', name: 'US Dollar' },
                ],
                profile: [{ code: 'EUR', name: 'Euro' }],
            },
        },
    }),
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
});
