import { SavingsGoal } from '@/types/savings-goal';
import { ServerTransaction } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { LinkTransactionsDialog } from './link-transactions-dialog';

const reload = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        reload: (...args: unknown[]) => reload(...args),
        put: vi.fn(),
    },
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

vi.mock('@/components/shared/label-combobox', () => ({
    LabelBadge: () => <div />,
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: () => <span />,
}));

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), success: vi.fn() },
}));

const PAGE_SIZE = 2;

const savingsGoal = {
    id: 'goal-1',
    label_id: 'label-goal',
    name: 'Trip to Japan',
} as SavingsGoal;

const transaction = (id: string): ServerTransaction =>
    ({
        id,
        description: `Transaction ${id}`,
        transaction_date: '2026-08-01',
        amount: -1000,
        labels: [],
    }) as unknown as ServerTransaction;

const renderDialog = (recentTransactions?: ServerTransaction[]) =>
    render(
        <LinkTransactionsDialog
            savingsGoal={savingsGoal}
            transactions={[]}
            recentTransactions={recentTransactions}
            recentPageSize={PAGE_SIZE}
            currencyCode="EUR"
            open
            onOpenChange={vi.fn()}
        />,
    );

describe('LinkTransactionsDialog load more', () => {
    beforeEach(() => {
        reload.mockClear();
    });

    it('offers to load more only while a page comes back full', () => {
        const { unmount } = renderDialog([transaction('a'), transaction('b')]);

        expect(screen.getByText('Load more')).toBeInTheDocument();
        unmount();

        renderDialog([transaction('a')]);

        expect(screen.queryByText('Load more')).not.toBeInTheDocument();
    });

    it('asks for one more page without leaving it in the address bar', () => {
        renderDialog([transaction('a'), transaction('b')]);
        reload.mockClear();

        fireEvent.click(screen.getByText('Load more'));

        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['recentTransactions'],
                data: { recent: PAGE_SIZE * 2 },
                preserveUrl: true,
            }),
        );
    });

    // Losing the ticks mid-dialog would be silent data loss: saving then detaches
    // the label from everything the user had already linked.
    it('keeps what the user ticked when a wider page arrives', () => {
        const { rerender } = renderDialog([transaction('a'), transaction('b')]);

        fireEvent.click(screen.getAllByRole('checkbox')[0]);
        expect(screen.getByText('1 selected')).toBeInTheDocument();

        rerender(
            <LinkTransactionsDialog
                savingsGoal={savingsGoal}
                transactions={[]}
                recentTransactions={[
                    transaction('a'),
                    transaction('b'),
                    transaction('c'),
                    transaction('d'),
                ]}
                recentPageSize={PAGE_SIZE}
                currencyCode="EUR"
                open
                onOpenChange={vi.fn()}
            />,
        );

        expect(screen.getAllByRole('checkbox')).toHaveLength(4);
        expect(screen.getByText('1 selected')).toBeInTheDocument();
    });
});
