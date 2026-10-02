import { Category } from '@/types/category';
import { describe, expect, it } from 'vitest';

import {
    buildCategoryTree,
    categorySelectionState,
    flattenCategoryTree,
    searchCategoryTree,
    searchTree,
    toggleCategorySelection,
} from './category-tree';

function category(id: string, parent_id: string | null): Category {
    return {
        id,
        name: id,
        icon: 'Tag',
        color: 'gray',
        type: 'expense',
        cashflow_direction: 'hidden',
        parent_id,
    } as Category;
}

// food ──┬─ groceries ── coffee
//        └─ restaurants
// drinks (root)
const categories: Category[] = [
    category('food', null),
    category('groceries', 'food'),
    category('restaurants', 'food'),
    category('coffee', 'groceries'),
    category('drinks', null),
];

const stateOf = (id: string, selected: string[]) =>
    categorySelectionState(id, new Set(selected), categories);

describe('categorySelectionState', () => {
    it('marks a category and its descendants checked when the parent is selected', () => {
        expect(stateOf('food', ['food'])).toBe('checked');
        expect(stateOf('groceries', ['food'])).toBe('checked');
        expect(stateOf('coffee', ['food'])).toBe('checked');
        expect(stateOf('drinks', ['food'])).toBe('unchecked');
    });

    it('marks ancestors indeterminate when only a descendant is selected', () => {
        expect(stateOf('food', ['coffee'])).toBe('indeterminate');
        expect(stateOf('groceries', ['coffee'])).toBe('indeterminate');
        expect(stateOf('coffee', ['coffee'])).toBe('checked');
    });
});

describe('toggleCategorySelection', () => {
    it('selecting a parent collapses to a single id covering the subtree', () => {
        expect(toggleCategorySelection([], 'food', categories)).toEqual([
            'food',
        ]);
    });

    it('unselecting one child keeps its siblings selected', () => {
        const result = toggleCategorySelection(
            ['food'],
            'restaurants',
            categories,
        );

        expect(result).not.toContain('food');
        expect(result).not.toContain('restaurants');
        expect(stateOf('groceries', result)).toBe('checked');
        expect(stateOf('restaurants', result)).toBe('unchecked');
        expect(stateOf('food', result)).toBe('indeterminate');
    });

    it('re-selecting a sibling does not auto-collapse to the parent, but clicking the parent does', () => {
        const partial = toggleCategorySelection(
            ['food'],
            'restaurants',
            categories,
        );
        const reselected = toggleCategorySelection(
            partial,
            'restaurants',
            categories,
        );

        // Both children selected individually; the parent is not auto-added.
        expect(reselected).not.toContain('food');
        expect(stateOf('groceries', reselected)).toBe('checked');
        expect(stateOf('restaurants', reselected)).toBe('checked');

        // Clicking the parent collapses the whole subtree to a single id.
        expect(toggleCategorySelection(reselected, 'food', categories)).toEqual(
            ['food'],
        );
    });

    it('selecting a single child never pulls in its parent', () => {
        const result = toggleCategorySelection([], 'coffee', categories);

        expect(result).toEqual(['coffee']);
        expect(stateOf('groceries', result)).toBe('indeterminate');
        expect(stateOf('food', result)).toBe('indeterminate');
    });
});

describe('searchCategoryTree', () => {
    const ordered = flattenCategoryTree(buildCategoryTree(categories));
    const search = (query: string) =>
        searchCategoryTree(ordered, query).map((category) => category.id);

    it('returns every category in tree order when the query is blank', () => {
        expect(search('')).toEqual([
            'drinks',
            'food',
            'groceries',
            'coffee',
            'restaurants',
        ]);
        expect(search('   ')).toEqual(search(''));
    });

    it('shows the whole subtree when a parent matches', () => {
        expect(search('food')).toEqual([
            'food',
            'groceries',
            'coffee',
            'restaurants',
        ]);
    });

    it('shows a matching child with its ancestors and its own children, but not its siblings', () => {
        expect(search('groceries')).toEqual(['food', 'groceries', 'coffee']);
    });

    it('shows a matching leaf under its ancestor chain', () => {
        expect(search('coffee')).toEqual(['food', 'groceries', 'coffee']);
    });

    it('matches case-insensitively and ignores surrounding spaces', () => {
        expect(search('  FOO ')).toEqual(search('food'));
    });

    it('returns nothing when no category matches', () => {
        expect(search('travel')).toEqual([]);
    });

    it('terminates on a corrupt parent cycle', () => {
        const cyclic = [category('a', 'b'), category('b', 'a')];

        expect(
            searchCategoryTree(cyclic, 'a').map((category) => category.id),
        ).toEqual(['a', 'b']);
    });
});

describe('searchTree', () => {
    const options = [
        { value: 'food', label: 'Food', parentValue: null },
        { value: 'groceries', label: 'Groceries', parentValue: 'food' },
        { value: 'restaurants', label: 'Restaurants', parentValue: 'food' },
        { value: 'drinks', label: 'Drinks' },
    ];
    const accessors = {
        id: (option: (typeof options)[number]) => option.value,
        parentId: (option: (typeof options)[number]) => option.parentValue,
        label: (option: (typeof options)[number]) => option.label,
    };

    it('reads ids, parents and labels through the given accessors', () => {
        expect(
            searchTree(options, 'food', accessors).map((o) => o.value),
        ).toEqual(['food', 'groceries', 'restaurants']);
        expect(
            searchTree(options, 'rest', accessors).map((o) => o.value),
        ).toEqual(['food', 'restaurants']);
    });

    it('keeps the input order instead of grouping matches first', () => {
        expect(searchTree(options, 'r', accessors).map((o) => o.value)).toEqual(
            ['food', 'groceries', 'restaurants', 'drinks'],
        );
    });
});
