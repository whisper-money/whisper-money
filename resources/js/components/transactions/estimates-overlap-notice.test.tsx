import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { EstimatesOverlapNotice } from './estimates-overlap-notice';

const { get, deleteMany } = vi.hoisted(() => ({
    get: vi.fn(),
    deleteMany: vi.fn(),
}));

vi.mock('axios', () => ({ default: { get } }));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { deleteMany },
}));

const ESTIMATES = [
    {
        id: 'estimate-1',
        description: 'Rent or mortgage',
        transaction_date: '2026-10-01',
        amount: -90000,
        currency_code: 'EUR',
    },
    {
        id: 'estimate-2',
        description: 'Groceries',
        transaction_date: '2026-10-01',
        amount: -35000,
        currency_code: 'EUR',
    },
];

async function renderNotice(
    transactions: { transaction_date: string; selected?: boolean }[],
) {
    render(
        <EstimatesOverlapNotice
            accountId="account-1"
            transactions={transactions}
        />,
    );

    await act(async () => {
        await Promise.resolve();
    });
}

describe('EstimatesOverlapNotice', () => {
    afterEach(() => {
        get.mockReset();
        deleteMany.mockReset();
    });

    it('asks about the months the selected movements fall in', async () => {
        get.mockResolvedValue({ data: { data: [] } });

        await renderNotice([
            { transaction_date: '2026-10-14', selected: true },
            { transaction_date: '2026-09-02', selected: true },
            { transaction_date: '2026-10-01', selected: true },
            // A duplicate left out of the import covers nothing.
            { transaction_date: '2026-08-30', selected: false },
        ]);

        const url = decodeURIComponent(get.mock.calls[0][0] as string);
        expect(url).toContain('account_id=account-1');
        expect(url).toContain('months[]=2026-09&months[]=2026-10');
        expect(url).not.toContain('2026-08');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('warns about the double count and deletes the estimates in one go', async () => {
        get.mockResolvedValue({ data: { data: ESTIMATES } });
        deleteMany.mockResolvedValue(undefined);

        await renderNotice([{ transaction_date: '2026-10-14' }]);

        expect(screen.getByRole('alert')).toHaveTextContent(
            'This account already has 2 estimates in the months this file covers.',
        );

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Delete the 2 estimates' }),
            );
        });

        expect(deleteMany).toHaveBeenCalledWith(['estimate-1', 'estimate-2']);
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(screen.getByText('2 estimates deleted.')).toBeInTheDocument();
    });

    it('stays quiet when the lookup fails', async () => {
        get.mockRejectedValue(new Error('offline'));

        await renderNotice([{ transaction_date: '2026-10-14' }]);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
