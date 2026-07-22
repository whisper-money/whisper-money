import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { StackedBarShape } from './stacked-bar-chart';

describe('StackedBarShape', () => {
    it('uses card background for the rounded edge stroke', () => {
        const { container } = render(
            <svg>
                <StackedBarShape
                    x={0}
                    y={0}
                    width={20}
                    height={10}
                    fill="var(--color-chart-2)"
                    payload={{ asset: 10 }}
                    dataKey="asset"
                    dataKeys={['asset']}
                />
            </svg>,
        );

        const path = container.querySelector('path');

        expect(path?.getAttribute('stroke')).toBe('var(--card)');
        expect(path?.getAttribute('stroke-width')).toBe('1');
        expect(path?.getAttribute('stroke-linejoin')).toBe('round');
    });

    it('clamps radius for very short bar segments', () => {
        const { container } = render(
            <svg>
                <StackedBarShape
                    x={0}
                    y={0}
                    width={20}
                    height={2}
                    fill="var(--color-chart-2)"
                    payload={{ asset: 2 }}
                    dataKey="asset"
                    dataKeys={['asset']}
                />
            </svg>,
        );

        const path = container.querySelector('path');

        expect(path?.getAttribute('d')).toContain('M 1 0');
        expect(path?.getAttribute('d')).toContain('H 19');
    });

    it('keeps the bottom edge square when flatBottom is set', () => {
        const { container } = render(
            <svg>
                <StackedBarShape
                    x={0}
                    y={0}
                    width={20}
                    height={10}
                    fill="var(--color-chart-2)"
                    payload={{ asset: 10 }}
                    dataKey="asset"
                    dataKeys={['asset']}
                    flatBottom
                />
            </svg>,
        );

        const d = container.querySelector('path')?.getAttribute('d');

        // Rounded at the top (far end), square at the zero baseline.
        expect(d).toContain('Q 20 0 20 4');
        expect(d).toContain('V 10 H 0');
        expect(d).not.toContain('Q 20 10');
    });
});
