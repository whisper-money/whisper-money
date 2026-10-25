import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { BulkActionsBar } from './bulk-actions-bar';

const categories = [
    {
        id: 'category-1',
        name: 'Groceries',
        icon: 'ShoppingCart',
        color: 'green',
    },
] as unknown as Category[];

const labels: Label[] = [
    {
        id: 'label-1',
        user_id: 'user-1',
        name: 'Work',
        color: 'blue',
        created_at: '2026-05-11T00:00:00.000000Z',
        updated_at: '2026-05-11T00:00:00.000000Z',
        deleted_at: null,
    },
];

function renderBar(props: Partial<ComponentProps<typeof BulkActionsBar>> = {}) {
    const handlers = {
        onCategoryChange: vi.fn(),
        onLabelsChange: vi.fn(),
        onDelete: vi.fn(),
        onReEvaluateRules: vi.fn(),
        onSelectAll: vi.fn(),
        onClear: vi.fn(),
    };

    render(
        <BulkActionsBar
            selectedCount={3}
            categories={categories}
            labels={labels}
            {...handlers}
            {...props}
        />,
    );

    return handlers;
}

function press(key: string) {
    fireEvent.keyDown(document.activeElement ?? document.body, { key });
}

describe('BulkActionsBar', () => {
    it('renders nothing without a selection', () => {
        renderBar({ selectedCount: 0 });

        expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
    });

    it('shows the count and offers to select every matching row', () => {
        const { onSelectAll } = renderBar();

        expect(screen.getByTestId('bulk-selected-count')).toHaveTextContent(
            '3',
        );

        fireEvent.click(screen.getByTestId('bulk-select-all'));

        expect(onSelectAll).toHaveBeenCalledOnce();
    });

    it('opens the category picker on C and applies the pick', async () => {
        const { onCategoryChange } = renderBar();

        press('c');
        fireEvent.click(await screen.findByText('Groceries'));

        expect(onCategoryChange).toHaveBeenCalledWith('category-1');
    });

    it('opens the label picker on L', async () => {
        const { onLabelsChange } = renderBar();

        press('l');
        fireEvent.click(await screen.findByText('Work'));

        expect(onLabelsChange).toHaveBeenCalledWith(['label-1']);
    });

    it('re-evaluates the rules on R', () => {
        const { onReEvaluateRules } = renderBar();

        press('r');

        expect(onReEvaluateRules).toHaveBeenCalledOnce();
    });

    it('asks to delete on Backspace and on Delete', () => {
        const { onDelete } = renderBar();

        press('Backspace');
        press('Delete');

        expect(onDelete).toHaveBeenCalledTimes(2);
    });

    it('clears the selection on Escape', () => {
        const { onClear } = renderBar();

        press('Escape');

        expect(onClear).toHaveBeenCalledOnce();
    });

    it('leaves Escape to an open picker, which closes without clearing', async () => {
        const { onClear } = renderBar();

        press('c');
        await screen.findByText('Groceries');
        press('Escape');

        expect(screen.queryByText('Groceries')).not.toBeInTheDocument();
        expect(onClear).not.toHaveBeenCalled();
    });

    it('offers no delete while every matching row is selected', () => {
        const { onDelete } = renderBar({ isSelectingAll: true });

        press('Backspace');

        expect(onDelete).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: /Delete/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('All matching filters')).toBeInTheDocument();
    });

    it('ignores the shortcuts while an update is running', () => {
        const { onReEvaluateRules, onDelete, onClear } = renderBar({
            isUpdating: true,
        });

        press('r');
        press('Backspace');
        press('c');
        press('Escape');

        expect(onReEvaluateRules).not.toHaveBeenCalled();
        expect(onDelete).not.toHaveBeenCalled();
        expect(screen.queryByText('Groceries')).not.toBeInTheDocument();
        expect(onClear).not.toHaveBeenCalled();
    });
});
