import type { SavingsGoal } from '@/types/savings-goal';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ArchiveSavingsGoalDialog } from './archive-savings-goal-dialog';

const post = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
    },
}));

vi.mock('@/actions/App/Http/Controllers/SavingsGoalController', () => ({
    archive: {
        url: ({ savingsGoal }: { savingsGoal: string }) =>
            `/savings-goals/${savingsGoal}/archive`,
    },
}));

const savingsGoal = { id: 'goal-1', name: 'New car' } as SavingsGoal;

describe('ArchiveSavingsGoalDialog', () => {
    it('warns the label goes and the amount freezes', () => {
        render(
            <ArchiveSavingsGoalDialog
                savingsGoal={savingsGoal}
                open={true}
                onOpenChange={() => {}}
            />,
        );

        expect(
            screen.getByText(/Archiving New car puts it away/),
        ).toBeInTheDocument();
        expect(screen.getByText(/Its label is removed/)).toBeInTheDocument();
        expect(
            screen.getByText(/automation rule that adds that label/),
        ).toBeInTheDocument();
        expect(screen.getByText(/amount saved is frozen/)).toBeInTheDocument();
        expect(screen.getByText(/This cannot be undone/)).toBeInTheDocument();
    });

    it('posts to the archive endpoint of the goal it was given', () => {
        render(
            <ArchiveSavingsGoalDialog
                savingsGoal={savingsGoal}
                open={true}
                onOpenChange={() => {}}
            />,
        );

        fireEvent.click(screen.getByText('Archive goal'));

        expect(post).toHaveBeenCalledWith(
            '/savings-goals/goal-1/archive',
            {},
            expect.anything(),
        );
    });
});
