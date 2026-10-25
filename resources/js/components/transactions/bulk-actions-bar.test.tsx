import { type MobileSelectionMode } from '@/hooks/use-mobile-selection-mode';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ComponentProps } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
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

describe('BulkActionsBar on a narrow desktop column', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    /** A content column narrower than the bar laid out in full. */
    function crowd() {
        vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(
            600,
        );
        vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(
            900,
        );
    }

    it('keeps every control, shrinking the actions to icons', () => {
        crowd();
        renderBar();

        expect(screen.getByRole('toolbar')).toHaveAttribute('data-compact');
        expect(screen.getByTestId('bulk-selected-count')).toHaveTextContent(
            '3',
        );

        for (const name of [
            'Select all matching filters',
            'Category',
            'Labels',
            'Re-evaluate rules',
            'Delete',
            'Clear selection',
        ]) {
            expect(screen.getByRole('button', { name })).toBeInTheDocument();
        }

        expect(
            screen.getByTestId('bulk-category-trigger'),
        ).not.toHaveTextContent('Category');
    });

    it('names the action and its key in a tooltip', async () => {
        crowd();
        renderBar();

        fireEvent.focus(
            screen.getByRole('button', { name: 'Re-evaluate rules' }),
        );

        const tooltip = await screen.findByRole('tooltip');

        expect(tooltip).toHaveTextContent('Re-evaluate rules');
        expect(tooltip.querySelector('kbd')).toHaveTextContent('R');
    });

    it('still answers the shortcuts', () => {
        crowd();
        const { onReEvaluateRules } = renderBar();

        press('r');

        expect(onReEvaluateRules).toHaveBeenCalledOnce();
    });

    it('lays the actions out in full when the column has room', () => {
        renderBar();

        expect(screen.getByRole('toolbar')).not.toHaveAttribute('data-compact');
        expect(screen.getByTestId('bulk-category-trigger')).toHaveTextContent(
            'Category',
        );
    });
});

function mobileSelection(
    overrides: Partial<MobileSelectionMode> = {},
): MobileSelectionMode {
    const forRows = {
        isMobile: true,
        isActive: true,
        enterWith: vi.fn(),
        toggleRow: vi.fn(),
    };

    return {
        ...forRows,
        forRows,
        selectedCount: 3,
        allVisibleSelected: false,
        enter: vi.fn(),
        exit: vi.fn(),
        clear: vi.fn(),
        deselectAll: vi.fn(),
        toggleAllVisible: vi.fn(),
        ...overrides,
    };
}

function openMoreActions() {
    fireEvent.pointerDown(
        screen.getByRole('button', { name: 'More actions' }),
        {
            button: 0,
            ctrlKey: false,
        },
    );
}

describe('BulkActionsBar on the phone', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('renders the compact bar, with no shortcuts, while selecting', () => {
        const { onReEvaluateRules, onClear } = renderBar({
            selectionMode: mobileSelection(),
        });

        expect(screen.getByTestId('bulk-selected-count')).toHaveTextContent(
            '3 selected',
        );
        expect(
            screen.getByRole('button', { name: 'Labels' }),
        ).toBeInTheDocument();

        press('r');
        press('Escape');

        expect(onReEvaluateRules).not.toHaveBeenCalled();
        expect(onClear).not.toHaveBeenCalled();
    });

    it('renders nothing outside selection mode, whatever is selected', () => {
        renderBar({ selectionMode: mobileSelection({ isActive: false }) });

        expect(screen.queryByRole('toolbar')).not.toBeInTheDocument();
    });

    it('stays up with nothing selected, its actions disabled', () => {
        renderBar({
            selectedCount: 0,
            selectionMode: mobileSelection({ selectedCount: 0 }),
        });

        expect(screen.getByTestId('bulk-selected-count')).toHaveTextContent(
            'Tap to select',
        );
        expect(screen.getByTestId('bulk-category-trigger')).toBeDisabled();
        expect(screen.getByTestId('bulk-labels-trigger')).toBeDisabled();
    });

    it('opens the category picker as a drawer and applies the pick', async () => {
        // vaul reads it when the drawer opens; jsdom ships none.
        vi.stubGlobal('matchMedia', () => ({ matches: false }));
        const { onCategoryChange } = renderBar({
            selectionMode: mobileSelection(),
        });

        fireEvent.click(screen.getByTestId('bulk-category-trigger'));

        expect(await screen.findByText('Change category')).toBeInTheDocument();

        fireEvent.click(await screen.findByText('Groceries'));

        expect(onCategoryChange).toHaveBeenCalledWith('category-1');
    });

    it('offers the rest behind the ⋯ menu', async () => {
        const selectionMode = mobileSelection();
        const { onReEvaluateRules, onDelete, onSelectAll } = renderBar({
            selectionMode,
        });

        openMoreActions();
        fireEvent.click(await screen.findByText('Re-evaluate rules'));
        openMoreActions();
        fireEvent.click(await screen.findByText('Delete 3'));
        openMoreActions();
        fireEvent.click(await screen.findByText('Select all matching filters'));
        openMoreActions();
        fireEvent.click(await screen.findByText('Done'));

        expect(onReEvaluateRules).toHaveBeenCalledOnce();
        expect(onDelete).toHaveBeenCalledOnce();
        expect(onSelectAll).toHaveBeenCalledOnce();
        expect(selectionMode.toggleAllVisible).toHaveBeenCalledOnce();
        expect(selectionMode.exit).toHaveBeenCalledOnce();
    });

    it('disables delete while every matching row is selected', async () => {
        const onSelectOnlyVisible = vi.fn();
        const selectionMode = mobileSelection();
        renderBar({
            isSelectingAll: true,
            onSelectOnlyVisible,
            selectionMode,
        });

        expect(screen.getByTestId('bulk-selected-count')).toHaveTextContent(
            'All matching filters',
        );

        openMoreActions();

        expect(
            (await screen.findByText('Delete')).closest('[role="menuitem"]'),
        ).toHaveAttribute('data-disabled');

        fireEvent.click(screen.getByText('Only visible'));
        openMoreActions();
        fireEvent.click(await screen.findByText('Deselect all'));

        expect(onSelectOnlyVisible).toHaveBeenCalledOnce();
        // Drops "all matching filters" too, not just the loaded rows.
        expect(selectionMode.deselectAll).toHaveBeenCalledOnce();
        expect(selectionMode.toggleAllVisible).not.toHaveBeenCalled();
    });
});
