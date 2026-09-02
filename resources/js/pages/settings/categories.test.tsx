import { EditCategoryDialog } from '@/components/categories/edit-category-dialog';
import type { Category } from '@/types/category';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import CategoriesPage from './categories';

globalThis.ResizeObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
};

const page = vi.hoisted(() => ({ categories: [] as unknown[] }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => ({ props: { categories: page.categories } }),
    Form: ({
        children,
        action,
    }: {
        children: (args: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
        action?: string;
    }) => (
        <form action={action}>
            {children({ errors: {}, processing: false })}
        </form>
    ),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

function makeCategory(overrides: Partial<Category>): Category {
    return {
        id: 'category-1',
        name: 'Category',
        icon: 'Utensils',
        color: 'blue',
        type: 'expense',
        cashflow_direction: 'hidden',
        parent_id: null,
        ...overrides,
    };
}

const food = makeCategory({ id: 'food', name: 'Food' });
const groceries = makeCategory({
    id: 'groceries',
    name: 'Groceries',
    icon: 'ShoppingBasket',
    parent_id: 'food',
});
const transportation = makeCategory({
    id: 'transportation',
    name: 'Transportation',
    icon: 'Car',
});
const fuel = makeCategory({
    id: 'fuel',
    name: 'Fuel',
    icon: 'Fuel',
    parent_id: 'transportation',
});
const salary = makeCategory({
    id: 'salary',
    name: 'Salary',
    icon: 'Banknote',
    type: 'income',
    cashflow_direction: 'inflow',
});
const bonus = makeCategory({
    id: 'bonus',
    name: 'Bonus',
    icon: 'Gift',
    type: 'income',
    cashflow_direction: 'inflow',
    parent_id: 'salary',
});

const allCategories = [food, groceries, transportation, fuel, salary, bonus];

/**
 * Renaming a top-level category moves it and its children to the front of the
 * alphabetical list, shifting the position of every row below it.
 */
function renameTransportation() {
    page.categories = [
        food,
        groceries,
        { ...transportation, name: 'Automovil' },
        fuel,
    ];
}

function openEditDialogFor(name: string) {
    const row = screen.getByText(name).closest('tr');
    expect(row).not.toBeNull();

    fireEvent.contextMenu(row!);
    fireEvent.click(screen.getByRole('menuitem', { name: 'Edit' }));
}

/** What the open dialog would actually send on Update. */
function submittedField(name: string): string | undefined {
    return screen
        .getByRole('dialog')
        .querySelector<HTMLInputElement>(`input[name="${name}"]`)?.value;
}

function submittedCategoryId(): string | undefined {
    return screen
        .getByRole('dialog')
        .querySelector('form')
        ?.getAttribute('action')
        ?.split('?')[0]
        .split('/')
        .pop();
}

describe('CategoriesPage', () => {
    it('keeps every category with its own parent after a rename reorders the table', () => {
        page.categories = [food, groceries, transportation, fuel];
        const { rerender } = render(<CategoriesPage />);

        renameTransportation();
        rerender(<CategoriesPage />);

        openEditDialogFor('Groceries');

        // Positional row keys handed this row the dialog built for whichever
        // category sat at its index before, silently reparenting on submit.
        expect(submittedField('parent_id')).toBe('food');
    });

    it('keeps an open dialog on the category it was opened from when the table reorders', () => {
        page.categories = [food, groceries, transportation, fuel];
        const { rerender } = render(<CategoriesPage />);

        openEditDialogFor('Fuel');
        expect(submittedCategoryId()).toBe('fuel');

        renameTransportation();
        rerender(<CategoriesPage />);

        expect(submittedCategoryId()).toBe('fuel');
    });
});

describe('EditCategoryDialog', () => {
    const renderDialog = (category: Category, open: boolean) => (
        <EditCategoryDialog
            category={category}
            categories={allCategories}
            open={open}
            onOpenChange={vi.fn()}
        />
    );

    it('rebuilds the parent and type it submits from the category it is handed on each open', () => {
        // The row this dialog belongs to can be re-pointed at another category
        // while the dialog is closed; what it submits has to follow.
        const { rerender } = render(renderDialog(bonus, false));

        rerender(renderDialog(groceries, true));

        expect(submittedField('parent_id')).toBe('food');
        expect(submittedField('type')).toBe('expense');
    });
});
