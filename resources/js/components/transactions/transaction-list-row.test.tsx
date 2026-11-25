import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
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
    overrides: Partial<DecryptedTransaction> = {},
): DecryptedTransaction {
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
    } as unknown as DecryptedTransaction;
}

function renderRow(
    props: Partial<ComponentProps<typeof TransactionListRow>> = {},
) {
    const handlers = {
        onToggleSelect: vi.fn(),
        onOpen: vi.fn(),
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

    describe('a split transaction', () => {
        const rent = { ...groceries, id: 'cat-rent', name: 'Rent' };
        const splits = [
            {
                id: 'line-1',
                transaction_id: 'tx-1',
                category_id: groceries.id,
                category: groceries,
                amount: -3000,
                position: 0,
            },
            {
                id: 'line-2',
                transaction_id: 'tx-1',
                category_id: rent.id,
                category: rent,
                amount: -1250,
                position: 1,
            },
        ];
        const split = buildTransaction({ category_id: null, splits });

        it('says it is split and lists its lines, never that it is uncategorized', () => {
            renderRow({ transaction: split });

            expect(row()).toHaveTextContent('Split · 2');
            expect(row()).toHaveTextContent('Groceries-€30.00');
            expect(row()).toHaveTextContent('Rent-€12.50');
            expect(row()).not.toHaveTextContent('Uncategorized');
        });

        it('lists the filtered lines and folds the rest under a category filter', () => {
            renderRow({
                transaction: split,
                categoryFilterIds: new Set([groceries.id]),
            });

            expect(row()).toHaveTextContent('Groceries-€30.00');
            expect(row()).toHaveTextContent('Rest-€12.50');
            expect(row()).not.toHaveTextContent('Rent');
        });
    });

    it('marks a row that arrived since the last visit, in both themes', () => {
        renderRow({ isNew: true });

        expect(row()).toHaveAttribute('data-new', 'true');
        expect(row()).toHaveClass(
            'border-l-blue-500',
            'bg-blue-500/5',
            'dark:bg-blue-500/10',
        );
    });

    it('leaves an old row unmarked', () => {
        renderRow();

        expect(row()).not.toHaveAttribute('data-new');
        expect(row()).not.toHaveClass('border-l-blue-500');
    });

    it('never prints a legacy encrypted description or note', () => {
        renderRow({
            transaction: buildTransaction({
                description: 'ciphertext',
                description_iv: 'iv',
                notes: 'more-ciphertext',
                notes_iv: 'iv',
            }),
        });

        expect(row()).not.toHaveTextContent('ciphertext');
        expect(
            screen.getByRole('button', { name: /^Select ••/ }),
        ).toBeInTheDocument();
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

    it('is read-only without handlers: no button, no pointer', () => {
        renderRow({ onOpen: undefined, onToggleSelect: undefined });

        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(row()).not.toHaveClass('cursor-pointer');
        expect(row()).not.toHaveClass('hover:bg-muted/50');
        expect(row()).toHaveTextContent('Mercadona');
    });

    it('tags the row after its description', () => {
        renderRow({ badge: 'Compra' });

        expect(row()).toHaveTextContent('MercadonaCompra-€42.50');
    });

    it('marks a part of a split, and a tap on the mark does not open the row', () => {
        const onUnsplit = vi.fn();
        const { onOpen } = renderRow({
            transaction: buildTransaction({ split_parent_id: 'tx-0' }),
            onUnsplit,
        });

        fireEvent.click(screen.getByTestId('split-origin'));

        expect(onOpen).not.toHaveBeenCalled();
    });

    it('leaves a part unmarked in a list that is only read', () => {
        renderRow({
            transaction: buildTransaction({ split_parent_id: 'tx-0' }),
            onUnsplit: undefined,
        });

        expect(screen.queryByTestId('split-origin')).not.toBeInTheDocument();
    });
});
