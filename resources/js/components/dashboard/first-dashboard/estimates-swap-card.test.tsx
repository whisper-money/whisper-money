import { type DashboardEstimates } from '@/types/dashboard';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { EstimatesSwapCard } from './estimates-swap-card';

const { showImporter, sendImportLink } = vi.hoisted(() => ({
    showImporter: vi.fn(),
    sendImportLink: vi.fn(),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/components/transactions/use-import-dialog', () => ({
    useImportDialog: () => ({ show: showImporter, dialog: null }),
}));

vi.mock('@/lib/send-import-link', () => ({ sendImportLink }));

const estimates: DashboardEstimates = {
    month: '2026-10',
    count: 4,
    total: 169500,
    currency_code: 'EUR',
    account_id: 'account-1',
    rows: [],
};

describe('EstimatesSwapCard', () => {
    afterEach(() => {
        showImporter.mockReset();
        sendImportLink.mockReset();
    });

    it('asks for the real month in place of the estimates', () => {
        render(<EstimatesSwapCard estimates={estimates} />);

        expect(
            screen.getByRole('heading', {
                name: 'Swap your estimates for the real thing',
            }),
        ).toBeTruthy();
        expect(
            screen.getByText(
                "You typed October in round figures. Import your bank's export and we replace the 4 estimates with your real movements — and start the spending history from there.",
            ),
        ).toBeTruthy();
    });

    it('opens the importer on the account the estimates are in', () => {
        render(<EstimatesSwapCard estimates={estimates} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'Import a bank file' }),
        );

        expect(showImporter).toHaveBeenCalledWith('account-1');
    });

    it('emails the import link once, and says it went out', async () => {
        sendImportLink.mockResolvedValue(true);
        render(<EstimatesSwapCard estimates={estimates} />);

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Email me a link' }),
            );
        });

        expect(sendImportLink).toHaveBeenCalledWith('account-1');
        expect(
            (
                screen.getByRole('button', {
                    name: 'Link sent',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('lets the link be asked for again when it did not go out', async () => {
        sendImportLink.mockResolvedValue(false);
        render(<EstimatesSwapCard estimates={estimates} />);

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Email me a link' }),
            );
        });

        expect(
            (
                screen.getByRole('button', {
                    name: 'Email me a link',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(false);
    });

    it('offers no link when the account cannot take a file', () => {
        render(
            <EstimatesSwapCard
                estimates={{ ...estimates, account_id: null, count: 1 }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Email me a link' }),
        ).toBeNull();
        expect(
            screen.getByText(
                "You typed October in round figures. Import your bank's export and we replace the estimate with your real movements — and start the spending history from there.",
            ),
        ).toBeTruthy();
    });
});
