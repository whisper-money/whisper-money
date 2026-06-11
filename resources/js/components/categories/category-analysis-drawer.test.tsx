import {
    CATEGORY_ANALYSIS_STORAGE_PREFIX,
    resolveInitialCategory,
} from '@/components/categories/category-analysis-drawer';
import { type Category } from '@/types/category';
import { beforeEach, describe, expect, it } from 'vitest';

function category(id: string): Category {
    return {
        id,
        name: id,
        icon: 'HelpCircle',
        color: 'gray',
        type: 'expense',
        cashflow_direction: 'outflow',
        parent_id: null,
    } as Category;
}

const categories = [category('a'), category('b'), category('c')];

function installMemoryStorage(): void {
    const store = new Map<string, string>();
    Object.defineProperty(globalThis, 'localStorage', {
        configurable: true,
        value: {
            getItem: (key: string) => store.get(key) ?? null,
            setItem: (key: string, value: string) => store.set(key, value),
            removeItem: (key: string) => store.delete(key),
            clear: () => store.clear(),
        },
    });
}

describe('resolveInitialCategory', () => {
    beforeEach(() => {
        installMemoryStorage();
    });

    it('prefers a stored category that still exists', () => {
        localStorage.setItem(`${CATEGORY_ANALYSIS_STORAGE_PREFIX}widget`, 'b');

        expect(resolveInitialCategory('widget', 'a', categories)).toBe('b');
    });

    it('falls back to the first category when nothing is stored', () => {
        expect(resolveInitialCategory('widget', 'a', categories)).toBe('a');
    });

    it('ignores a stored category that no longer exists', () => {
        localStorage.setItem(
            `${CATEGORY_ANALYSIS_STORAGE_PREFIX}widget`,
            'deleted',
        );

        expect(resolveInitialCategory('widget', 'c', categories)).toBe('c');
    });

    it('remembers each widget independently', () => {
        localStorage.setItem(`${CATEGORY_ANALYSIS_STORAGE_PREFIX}left`, 'a');
        localStorage.setItem(`${CATEGORY_ANALYSIS_STORAGE_PREFIX}right`, 'c');

        expect(resolveInitialCategory('left', 'b', categories)).toBe('a');
        expect(resolveInitialCategory('right', 'b', categories)).toBe('c');
    });

    it('returns null when neither a stored nor a first category is valid', () => {
        expect(resolveInitialCategory('widget', null, categories)).toBeNull();
        expect(
            resolveInitialCategory('widget', 'missing', categories),
        ).toBeNull();
    });
});
