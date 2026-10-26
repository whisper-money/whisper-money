import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionCard } from './transaction-card';

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { update: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn(), delete: vi.fn() },
    usePage: () => ({
        props: {
            auth: { hasProPlan: true },
            subscriptionsEnabled: false,
            aiCategorizationUpsellRate: 0,
        },
    }),
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

const dining = {
    ...groceries,
    id: 'cat-dining',
    name: 'Dining',
    icon: 'Utensils',
    color: 'orange',
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

function renderCard(
    props: Partial<ComponentProps<typeof TransactionCard>> = {},
) {
    const handlers = {
        onToggleSelect: vi.fn(),
        onOpen: vi.fn(),
        onUpdate: vi.fn(),
        onUnsplit: vi.fn(),
    };

    render(
        <PrivacyModeProvider>
            <TransactionCard
                transaction={buildTransaction()}
                categories={[groceries, dining]}
                accounts={[]}
                banks={[]}
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

describe('TransactionCard', () => {
    beforeEach(() => {
        vi.mocked(transactionSyncService.update).mockReset();
    });

    it('shows the description, amount, account and category', () => {
        renderCard({
            transaction: buildTransaction({ notes: 'Weekly shop' }),
        });

        const card = screen.getByTestId('transaction-card');

        expect(card).toHaveTextContent('Mercadona');
        expect(card).toHaveTextContent('Main account');
        expect(card).toHaveTextContent('Weekly shop');
        expect(card).toHaveTextContent('42.50');
        expect(
            screen.getByTestId('transaction-card-category'),
        ).toHaveTextContent('Groceries');
    });

    it('offers to choose a category when there is none', () => {
        renderCard({ transaction: buildTransaction({ category_id: null }) });

        expect(
            screen.getByTestId('transaction-card-category'),
        ).toHaveTextContent('Choose category');
    });

    it('hides the label every card already carries', () => {
        renderCard({
            transaction: buildTransaction({
                label_ids: ['label-trip', 'label-home'],
            }),
            hiddenLabelId: 'label-trip',
        });

        const card = screen.getByTestId('transaction-card');

        expect(card).toHaveTextContent('Home');
        expect(card).not.toHaveTextContent('Trip');
    });

    it('shows the categorizing state while AI works on it', () => {
        renderCard({ isCategorizing: true });

        expect(screen.getByText('Categorizing…')).toBeInTheDocument();
        expect(
            screen.queryByTestId('transaction-card-category'),
        ).not.toBeInTheDocument();
    });

    it('toggles selection from the circle without opening the card', () => {
        const { onToggleSelect, onOpen } = renderCard();

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

    it('marks the circle and the card as selected', () => {
        renderCard({ selected: true });

        expect(
            screen.getByRole('button', { name: 'Select Mercadona' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByTestId('transaction-card')).toHaveAttribute(
            'data-selected',
            'true',
        );
    });

    it('opens the transaction when the body is tapped', () => {
        const { onToggleSelect, onOpen } = renderCard();

        fireEvent.click(screen.getByText('Mercadona'));

        expect(onOpen).toHaveBeenCalledOnce();
        expect(onToggleSelect).not.toHaveBeenCalled();
    });

    it('opens the transaction from the keyboard through its description', () => {
        const { onOpen } = renderCard();

        fireEvent.click(screen.getByRole('button', { name: 'Mercadona' }));

        expect(onOpen).toHaveBeenCalledOnce();
    });

    it('takes the chips out of reach while selection is active', () => {
        renderCard({ selectionActive: true });

        expect(
            screen.getByTestId('transaction-card-category').closest('[inert]'),
        ).not.toBeNull();
        expect(
            screen.getByTestId('transaction-card-circle').closest('[inert]'),
        ).toBeNull();
    });

    it('toggles instead of opening while selection is active', () => {
        const { onToggleSelect, onOpen } = renderCard({
            selectionActive: true,
        });

        fireEvent.click(screen.getByText('Mercadona'));

        expect(onToggleSelect).toHaveBeenCalledOnce();
        expect(onOpen).not.toHaveBeenCalled();
    });

    it('changes the category from the chip without opening the card', async () => {
        vi.mocked(transactionSyncService.update).mockResolvedValue(
            {} as Awaited<ReturnType<typeof transactionSyncService.update>>,
        );
        const { onUpdate, onOpen } = renderCard();

        fireEvent.click(screen.getByTestId('transaction-card-category'));
        fireEvent.click(await screen.findByRole('option', { name: 'Dining' }));

        await waitFor(() =>
            expect(onUpdate).toHaveBeenCalledWith(
                expect.objectContaining({ category_id: dining.id }),
            ),
        );
        expect(transactionSyncService.update).toHaveBeenCalledWith('tx-1', {
            category_id: dining.id,
        });
        expect(onOpen).not.toHaveBeenCalled();
    });
});
