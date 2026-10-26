import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { TransactionListRow } from './transaction-list-row';

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: {} }),
}));

const groceries = {
    id: 'cat-groceries',
    name: 'Groceries',
    icon: 'ShoppingBasket',
    color: 'emerald',
    type: 'expense',
    cashflow_direction: 'outflow',
    parent_id: null,
} as Category;

const labels = [
    { id: 'label-trip', name: 'Trip', color: 'blue' },
    { id: 'label-home', name: 'Home', color: 'green' },
] as Label[];

function buildTransaction(
    overrides: Partial<ServerTransaction> = {},
): ServerTransaction {
    return {
        id: 'tx-1',
        description: 'Mercadona',
        amount: -4250,
        currency_code: 'EUR',
        transaction_date: '2026-10-20',
        category_id: groceries.id,
        account_id: 'acc-1',
        account: { id: 'acc-1', name: 'Main account' },
        bank: { id: 'bank-1', name: 'BBVA', logo: null },
        label_ids: [],
        notes: null,
        ...overrides,
    } as unknown as ServerTransaction;
}

function renderRow(
    props: Partial<ComponentProps<typeof TransactionListRow>> = {},
) {
    const handlers = {
        onToggleSelect: vi.fn(),
        onOpen: vi.fn(),
        onUnsplit: vi.fn(),
    };

    render(
        <PrivacyModeProvider>
            <TransactionListRow
                transaction={buildTransaction()}
                categories={[groceries]}
                labels={labels}
                selected={false}
                selectionActive={false}
                {...handlers}
                {...props}
            />
        </PrivacyModeProvider>,
    );

    return handlers;
}

function row(): HTMLElement {
    return screen.getByTestId('transaction-list-row');
}

describe('TransactionListRow', () => {
    it('reads description and amount, then category and account, then the note', () => {
        renderRow({
            transaction: buildTransaction({ notes: 'Weekly shop' }),
        });

        expect(row()).toHaveTextContent(
            'Mercadona-€42.50Groceries·BMain accountWeekly shop',
        );
    });

    it('says when there is no category, and offers no picker', () => {
        renderRow({ transaction: buildTransaction({ category_id: null }) });

        expect(row()).toHaveTextContent('Uncategorized');
        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    });

    it('signs income', () => {
        renderRow({ transaction: buildTransaction({ amount: 180000 }) });

        expect(row()).toHaveTextContent('+€1,800.00');
    });

    it('puts the labels on a line of their own, between the account and the note', () => {
        renderRow({
            transaction: buildTransaction({
                label_ids: ['label-trip', 'label-home'],
                notes: 'Weekly shop',
            }),
        });

        const labelLine = screen.getByText('Trip').parentElement!;

        expect(labelLine).toHaveTextContent(/^TripHome$/);
        expect(labelLine).toHaveClass('flex-wrap');
        expect(labelLine.previousElementSibling).toHaveTextContent(
            'Main account',
        );
        expect(labelLine.nextElementSibling).toHaveTextContent('Weekly shop');
    });

    it('draws no label line when there is nothing to show', () => {
        renderRow({
            transaction: buildTransaction({ label_ids: ['label-trip'] }),
            hiddenLabelId: 'label-trip',
        });

        expect(row().querySelector('.flex-wrap')).toBeNull();
    });

    it('lists the labels, leaving out the one every row already carries', () => {
        renderRow({
            transaction: buildTransaction({
                label_ids: ['label-trip', 'label-home'],
            }),
            hiddenLabelId: 'label-trip',
        });

        expect(row()).toHaveTextContent('Home');
        expect(row()).not.toHaveTextContent('Trip');
    });

    it('shows the categorizing state while AI works on it', () => {
        renderRow({ isCategorizing: true });

        expect(row()).toHaveTextContent('Categorizing…');
        expect(row()).not.toHaveTextContent('Groceries');
    });

    it('toggles selection from the circle without opening the row', () => {
        const { onToggleSelect, onOpen } = renderRow();

        const circle = screen.getByRole('button', {
            name: 'Select Mercadona',
        });
        expect(circle).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(circle);

        expect(onToggleSelect).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'tx-1' }),
        );
        expect(onOpen).not.toHaveBeenCalled();
    });

    it('marks the circle and the row as selected', () => {
        renderRow({ selected: true });

        expect(
            screen.getByRole('button', { name: 'Select Mercadona' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(row()).toHaveAttribute('data-selected', 'true');
    });

    it('opens the transaction when the row is tapped', () => {
        const { onToggleSelect, onOpen } = renderRow();

        fireEvent.click(screen.getByText('Groceries'));

        expect(onOpen).toHaveBeenCalledOnce();
        expect(onToggleSelect).not.toHaveBeenCalled();
    });

    it('opens the transaction from the keyboard through its description', () => {
        const { onOpen } = renderRow();

        fireEvent.click(screen.getByRole('button', { name: 'Mercadona' }));

        expect(onOpen).toHaveBeenCalledOnce();
    });

    it('toggles instead of opening while selection is active', () => {
        const { onToggleSelect, onOpen } = renderRow({
            selectionActive: true,
        });

        fireEvent.click(screen.getByText('Mercadona'));

        expect(onToggleSelect).toHaveBeenCalledOnce();
        expect(onOpen).not.toHaveBeenCalled();
    });
});
