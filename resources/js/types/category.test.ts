import { setTranslations } from '@/utils/i18n';
import { afterEach, describe, expect, it } from 'vitest';
import {
    type CategoryColor,
    getCategoryColorClasses,
    getCategoryTypeLabel,
} from './category';

describe('getCategoryTypeLabel', () => {
    afterEach(() => {
        setTranslations({});
    });

    it('returns translated category type labels', () => {
        setTranslations({
            Income: 'Ingresos',
            Expense: 'Gasto',
            Transfer: 'Transferencia',
        });

        expect(getCategoryTypeLabel('income')).toBe('Ingresos');
        expect(getCategoryTypeLabel('expense')).toBe('Gasto');
        expect(getCategoryTypeLabel('transfer')).toBe('Transferencia');
    });

    it('falls back to English labels without translations', () => {
        expect(getCategoryTypeLabel('income')).toBe('Income');
        expect(getCategoryTypeLabel('expense')).toBe('Expense');
        expect(getCategoryTypeLabel('transfer')).toBe('Transfer');
    });
});

describe('getCategoryColorClasses', () => {
    it('returns the classes for a known color', () => {
        expect(getCategoryColorClasses('blue')).toEqual({
            bg: 'bg-blue-100 dark:bg-blue-700',
            text: 'text-blue-700 dark:text-blue-100',
        });
    });

    it('falls back to gray for an unknown color instead of returning undefined', () => {
        const classes = getCategoryColorClasses('chartreuse' as CategoryColor);

        expect(classes).toEqual(getCategoryColorClasses('gray'));
        expect(classes.bg).toBeDefined();
    });
});
