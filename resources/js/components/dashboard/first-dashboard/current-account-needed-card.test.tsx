import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CurrentAccountNeededCard } from './current-account-needed-card';

const { showImporter } = vi.hoisted(() => ({ showImporter: vi.fn() }));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

vi.mock('@/components/transactions/use-import-dialog', () => ({
    useImportDialog: () => ({ show: showImporter, dialog: null }),
}));

vi.mock('@/components/accounts/create-account-dialog', () => ({
    CreateAccountDialog: ({
        trigger,
        initialMode,
    }: {
        trigger: React.ReactNode;
        initialMode: string;
    }) => <div data-mode={initialMode}>{trigger}</div>,
}));

describe('CurrentAccountNeededCard', () => {
    afterEach(() => showImporter.mockReset());

    it('says what spending and cashflow need, set against the guess', () => {
        render(
            <CurrentAccountNeededCard
                spendingGuess={120000}
                currencyCode="EUR"
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Spending and cashflow need a current account',
            }),
        ).toBeTruthy();
        expect(
            screen.getByText(
                "An investment moves as one number. Add the account your salary lands in and this space shows where the money goes each month, what's left over, and how it compares with your guess of €1,200.",
            ),
        ).toBeTruthy();
    });

    it('leaves the guess out when there is none', () => {
        render(
            <CurrentAccountNeededCard
                spendingGuess={null}
                currencyCode="EUR"
            />,
        );

        expect(
            screen.getByText(
                "An investment moves as one number. Add the account your salary lands in and this space shows where the money goes each month and what's left over.",
            ),
        ).toBeTruthy();
    });

    it('offers the importer and the account form, straight on its manual side', () => {
        render(
            <CurrentAccountNeededCard
                spendingGuess={null}
                currencyCode="EUR"
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Import a bank file' }),
        );

        expect(showImporter).toHaveBeenCalledWith(null);
        expect(
            screen
                .getByRole('button', { name: 'Add an account by hand' })
                .closest('[data-mode]')
                ?.getAttribute('data-mode'),
        ).toBe('manual');
    });
});
