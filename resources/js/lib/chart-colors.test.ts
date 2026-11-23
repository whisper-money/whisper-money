import { readFileSync } from 'fs';
import { resolve } from 'path';
import { describe, expect, it } from 'vitest';
import {
    categoryChartColor,
    chartRoleColors,
    chartRoleTokens,
} from './chart-colors';

const appCss = readFileSync(resolve(__dirname, '../../css/app.css'), 'utf8');

describe('chart colors', () => {
    it('draws each role with its token', () => {
        expect(chartRoleColors.cashflowIncomeColor).toBe('var(--chart-income)');
        expect(Object.keys(chartRoleColors)).toEqual(
            Object.keys(chartRoleTokens),
        );
    });

    it('declares every role token in app.css', () => {
        const tokens = [
            ...Object.values(chartRoleTokens),
            '--chart-category-mix',
        ];

        // Once for neutral, blue and pink, once more for colorful.
        tokens.forEach((token) => {
            expect(appCss.split(`${token}:`).length - 1, token).toBe(2);
        });
    });

    it('mixes the category color and its slot through the theme', () => {
        expect(categoryChartColor('amber', 0)).toBe(
            'color-mix(in oklch, var(--color-amber-500) var(--chart-category-mix, 0%), var(--chart-1))',
        );
    });

    it('cycles the first eight slots', () => {
        expect(categoryChartColor('blue', 7)).toContain('var(--chart-8))');
        expect(categoryChartColor('blue', 8)).toContain('var(--chart-1))');
    });
});
