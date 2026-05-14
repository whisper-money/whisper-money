import { setTranslations } from '@/utils/i18n';
import { afterEach, describe, expect, it } from 'vitest';
import { getCategoryTypeLabel } from './category';

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
