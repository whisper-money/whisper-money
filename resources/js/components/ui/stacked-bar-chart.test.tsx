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
});
