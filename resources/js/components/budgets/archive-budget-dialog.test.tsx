import type { Budget } from '@/types/budget';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ArchiveBudgetDialog } from './archive-budget-dialog';

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
    },
}));

vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    archive: {
        url: ({ budget }: { budget: string }) => `/budgets/${budget}/archive`,
    },
}));

const budget = { id: 'bud-1', name: 'Groceries' } as Budget;

function open() {
    render(
        <ArchiveBudgetDialog
            budget={budget}
            open={true}
            onOpenChange={() => {}}
        />,
    );
}

describe('ArchiveBudgetDialog', () => {
    it('names the budget and spells out what archiving does', () => {
        open();

        expect(
            screen.getByText(/Archiving Groceries puts it away/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/stops counting from today/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/keep the figures they have now/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/go back to your catch-all budget/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/drops off your notification settings/),
        ).toBeInTheDocument();
    });

    it('says out loud that it cannot be undone', () => {
        open();

        expect(screen.getByText(/This cannot be undone/)).toBeInTheDocument();
    });

    it('posts to the archive endpoint of the budget it was given', () => {
        open();

        fireEvent.click(screen.getByRole('button', { name: 'Archive budget' }));

        expect(post).toHaveBeenCalledWith(
            '/budgets/bud-1/archive',
            {},
            expect.anything(),
        );
    });

    it('renders nothing while closed', () => {
        render(
            <ArchiveBudgetDialog
                budget={budget}
                open={false}
                onOpenChange={() => {}}
            />,
        );

        expect(screen.queryByText(/puts it away/)).not.toBeInTheDocument();
    });
});
