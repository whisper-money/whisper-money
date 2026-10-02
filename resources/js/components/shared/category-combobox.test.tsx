import {
    searchableCategories as categories,
    expectFoodListedWithSubcategories,
    listedOptions,
} from '@/lib/category-tree.fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CategoryCombobox } from './category-combobox';

function search(query: string) {
    const onValueChange = vi.fn();
    render(
        <CategoryCombobox
            value={null}
            onValueChange={onValueChange}
            categories={categories}
        />,
    );

    fireEvent.click(screen.getByRole('combobox'));
    const input = screen.getByPlaceholderText('Search categories...');
    fireEvent.change(input, { target: { value: query } });

    return { input, onValueChange };
}

describe('CategoryCombobox search', () => {
    it('lists the subcategories of a matching parent and picks the parent on Enter', async () => {
        const { input, onValueChange } = search('food');

        await expectFoodListedWithSubcategories();
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(onValueChange).toHaveBeenCalledWith('food');
    });

    it('shows a matching subcategory under its parent without its siblings', () => {
        search('groc');

        expect(listedOptions()).toEqual(['Food', 'Groceries']);
    });
});
