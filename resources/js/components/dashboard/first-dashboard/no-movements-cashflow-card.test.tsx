import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { NoMovementsCashflowCard } from './no-movements-cashflow-card';

const { showNewTransaction } = vi.hoisted(() => ({
    showNewTransaction: vi.fn(),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/components/transactions/use-new-transaction-dialog', () => ({
    useNewTransactionDialog: () => ({
        show: showNewTransaction,
        loading: false,
        dialog: null,
    }),
}));

describe('NoMovementsCashflowCard', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(2026, 10, 25, 12));
    });

    afterEach(() => {
        vi.useRealTimers();
        showNewTransaction.mockReset();
    });

    it("waits for the month's first movement with dashes, not zeros", () => {
        render(<NoMovementsCashflowCard />);

        expect(
            screen.getByText(
                "No November movements yet. Income, spending and what's left over appear here as soon as you import or add them.",
            ),
        ).toBeTruthy();
        expect(screen.getAllByText('—')).toHaveLength(3);
        expect(screen.queryByText(/0/)).toBeNull();
    });

    it('opens the new movement dialog', () => {
        render(<NoMovementsCashflowCard />);

        fireEvent.click(screen.getByRole('button', { name: 'Add a movement' }));

        expect(showNewTransaction).toHaveBeenCalled();
    });
});
