import { type Category } from '@/types/category';
import { type UUID } from '@/types/uuid';
import { screen, waitFor } from '@testing-library/react';
import { expect } from 'vitest';

const rows: [id: UUID, name: string, parentId: UUID | null][] = [
    ['food', 'Food', null],
    ['groceries', 'Groceries', 'food'],
    ['restaurants', 'Restaurants', 'food'],
    ['transport', 'Transport', null],
];

/**
 * A small two-level tree for the category pickers' search tests: Food with two
 * subcategories next to a childless Transport, listed parents first.
 */
export const searchableCategories: Category[] = rows.map(
    ([id, name, parent_id]) => ({
        id,
        name,
        parent_id,
        icon: 'Receipt',
        color: 'gray',
        type: 'expense',
        cashflow_direction: 'outflow',
    }),
);

/**
 * The labels of the rows an open picker lists, top to bottom.
 */
export function listedOptions(): (string | null)[] {
    return screen.getAllByRole('option').map((option) => option.textContent);
}

/**
 * After searching "food", a picker lists Food with both its subcategories and
 * keeps Food highlighted, so Enter picks the parent rather than its first child.
 */
export async function expectFoodListedWithSubcategories(): Promise<void> {
    expect(listedOptions()).toEqual(['Food', 'Groceries', 'Restaurants']);
    await waitFor(() =>
        expect(
            screen.getByRole('option', { selected: true }),
        ).toHaveTextContent('Food'),
    );
}
