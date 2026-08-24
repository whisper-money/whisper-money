import { type TransactionFilters } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { TransactionActionsMenu } from './transaction-actions-menu';

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    categorize: { url: () => '/transactions/categorize' },
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
}));

vi.mock('@/hooks/use-re-evaluate-all-transactions', () => ({
    useReEvaluateAllTransactions: () => ({ reEvaluateAll: vi.fn() }),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('./import-transactions-drawer', () => ({
    ImportTransactionsDrawer: () => null,
}));

vi.mock('./transaction-analysis-drawer', () => ({
    TransactionAnalysisDrawer: ({ open }: { open: boolean }) =>
        open ? <div data-testid="analysis-drawer" /> : null,
}));

const emptyFilters: TransactionFilters = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: [],
    accountIds: [],
    labelIds: [],
    creditorName: '',
    debtorName: '',
    searchText: '',
};

function renderMenu(filters: TransactionFilters) {
    return render(
        <TransactionActionsMenu
            categories={[]}
            accounts={[]}
            banks={[]}
            onAddTransaction={vi.fn()}
            transactions={[]}
            filters={filters}
        />,
    );
}

describe('TransactionActionsMenu analysis button', () => {
    it('is disabled when no filter is applied', () => {
        renderMenu(emptyFilters);

        expect(screen.getByText('Analysis').closest('button')).toHaveAttribute(
            'aria-disabled',
            'true',
        );
    });

    it('opens the analysis drawer when a filter is applied and clicked', () => {
        renderMenu({ ...emptyFilters, labelIds: ['label-1'] });

        const button = screen.getByText('Analysis').closest('button')!;
        expect(button).toHaveAttribute('aria-disabled', 'false');

        fireEvent.click(button);
        expect(screen.getByTestId('analysis-drawer')).toBeInTheDocument();
    });
});
