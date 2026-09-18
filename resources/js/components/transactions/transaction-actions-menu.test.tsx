import {
    type ServerTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { fireEvent, render, screen, within } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionActionsMenu } from './transaction-actions-menu';

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    categorize: { url: () => '/transactions/categorize' },
}));

let isMobile = false;

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => isMobile,
}));

vi.mock('@/hooks/use-re-evaluate-all-transactions', () => ({
    useReEvaluateAllTransactions: () => ({ reEvaluateAll: vi.fn() }),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => (
        <a {...props}>{children}</a>
    ),
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

function renderMenu(
    filters: TransactionFilters,
    transactions: ServerTransaction[] = [],
) {
    return render(
        <TransactionActionsMenu
            transactions={transactions}
            filters={filters}
        />,
    );
}

function uncategorized(id: string): ServerTransaction {
    return { id, category_id: null } as ServerTransaction;
}

function openMoreActionsMenu() {
    fireEvent.pointerDown(screen.getByLabelText('More actions'), {
        button: 0,
        ctrlKey: false,
    });

    return screen.findByRole('menu');
}

function actionBarLabels(container: HTMLElement) {
    return [...container.querySelectorAll('button, a')].map(
        (element) => element.getAttribute('aria-label') ?? element.textContent,
    );
}

beforeEach(() => {
    isMobile = false;
});

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

describe('TransactionActionsMenu action bar layout', () => {
    it('shows analysis and categorize in that order on desktop', () => {
        const { container } = renderMenu(emptyFilters, [uncategorized('t-1')]);

        expect(actionBarLabels(container)).toEqual([
            'Analysis',
            'Categorize1',
            'More actions',
        ]);
    });

    it('keeps the same order when every transaction is categorized', () => {
        const { container } = renderMenu(emptyFilters);

        expect(actionBarLabels(container)).toEqual([
            'Analysis',
            'Categorize',
            'More actions',
        ]);
    });

    it('leaves creating and importing to the app header', async () => {
        renderMenu(emptyFilters);

        expect(
            screen.queryByRole('button', { name: 'Add transaction' }),
        ).not.toBeInTheDocument();

        const menu = await openMoreActionsMenu();
        expect(
            within(menu).queryByText('Import Transactions'),
        ).not.toBeInTheDocument();
    });

    it('moves categorize to the dropdown on mobile', async () => {
        isMobile = true;
        const { container } = renderMenu(emptyFilters, [
            uncategorized('t-1'),
            uncategorized('t-2'),
        ]);

        expect(actionBarLabels(container)).toEqual([
            'Analysis',
            'More actions',
        ]);

        const menu = await openMoreActionsMenu();
        expect(
            [...menu.querySelectorAll('[role="menuitem"]')].map(
                (item) => item.textContent,
            ),
        ).toEqual(['Categorize2', 'Update categories automatically']);
        expect(
            within(menu).getByText('Categorize').closest('a'),
        ).toHaveAttribute('href', '/transactions/categorize');
    });

    it('disables the categorize dropdown item when nothing is uncategorized', async () => {
        isMobile = true;
        renderMenu(emptyFilters);

        const menu = await openMoreActionsMenu();
        const categorizeItem = within(menu)
            .getByText('Categorize')
            .closest('[role="menuitem"]')!;

        expect(categorizeItem).toHaveAttribute('data-disabled');
        expect(categorizeItem.closest('a')).toBeNull();
    });
});
