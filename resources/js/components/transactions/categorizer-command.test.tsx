import {
    expectFoodListedWithSubcategories,
    searchableCategories,
} from '@/lib/category-tree.fixture';
import { type ServerTransaction } from '@/types/transaction';
import { fireEvent, render, screen } from '@testing-library/react';
import { createRef } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { CategorizerCommand } from './categorizer-command';

describe('CategorizerCommand search', () => {
    it('lists the subcategories of a matching parent and assigns the parent on Enter', async () => {
        const onCategorySelect = vi.fn();
        render(
            <CategorizerCommand
                sortedCategories={searchableCategories}
                animationState="idle"
                currentTransaction={{ id: 'tx-1' } as ServerTransaction}
                searchValue="food"
                onSearchChange={vi.fn()}
                onCategorySelect={onCategorySelect}
                commandInputRef={createRef<HTMLInputElement>()}
            />,
        );

        await expectFoodListedWithSubcategories();
        fireEvent.keyDown(screen.getByPlaceholderText('Search categories...'), {
            key: 'Enter',
        });

        expect(onCategorySelect).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'food' }),
        );
    });
});
