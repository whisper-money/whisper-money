import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HeaderAddTransactionButton } from './header-add-transaction-button';

let showHeaderAddTransaction = true;

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { showHeaderAddTransaction } }),
}));

const addTransactionButton = vi.fn();

vi.mock('./add-transaction-button', () => ({
    AddTransactionButton: (props: Record<string, unknown>) => {
        addTransactionButton(props);

        return <button data-testid={props.testId as string} />;
    },
}));

describe('HeaderAddTransactionButton', () => {
    beforeEach(() => {
        showHeaderAddTransaction = true;
        addTransactionButton.mockClear();
    });

    it('renders an outline button tagged with the header origin', () => {
        render(<HeaderAddTransactionButton />);

        expect(
            screen.getByTestId('header-add-transaction-button'),
        ).toBeInTheDocument();
        expect(addTransactionButton).toHaveBeenCalledWith(
            expect.objectContaining({ variant: 'outline', origin: 'header' }),
        );
    });

    it('renders nothing for readers who do not add transactions by hand', () => {
        showHeaderAddTransaction = false;
        render(<HeaderAddTransactionButton />);

        expect(
            screen.queryByTestId('header-add-transaction-button'),
        ).not.toBeInTheDocument();
    });
});
