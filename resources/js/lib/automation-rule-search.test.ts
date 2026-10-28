import type { Category } from '@/types/category';
import { describe, expect, it } from 'vitest';

import { searchAutomationRules } from './automation-rule-search';

function rule(title: string, categoryName?: string) {
    return {
        title,
        category: categoryName
            ? ({ name: categoryName } as Category)
            : undefined,
    };
}

const rules = [
    rule('AA · Dia', 'AA · Supermercado'),
    rule('GF · Whisper Money', 'GF · Servicios financieros digitales'),
    rule('Café de la esquina', 'Bares y cafeterías'),
    rule('Sin categoría'),
];

describe('searchAutomationRules', () => {
    it('keeps every rule for an empty or blank query', () => {
        expect(searchAutomationRules(rules, '')).toEqual(rules);
        expect(searchAutomationRules(rules, '   ')).toEqual(rules);
    });

    it('matches the rule title, ignoring case', () => {
        expect(searchAutomationRules(rules, 'whisper')).toEqual([rules[1]]);
    });

    it('matches the category name', () => {
        expect(searchAutomationRules(rules, 'supermercado')).toEqual([
            rules[0],
        ]);
    });

    it('ignores accents on either side', () => {
        expect(searchAutomationRules(rules, 'cafeterias')).toEqual([rules[2]]);
        expect(searchAutomationRules(rules, 'CAFÉ')).toEqual([rules[2]]);
    });

    it('ignores punctuation between words', () => {
        expect(searchAutomationRules(rules, 'aa dia')).toEqual([rules[0]]);
    });

    it('returns nothing when no title or category matches', () => {
        expect(searchAutomationRules(rules, 'netflix')).toEqual([]);
    });
});
