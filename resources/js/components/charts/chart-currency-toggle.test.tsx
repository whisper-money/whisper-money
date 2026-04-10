import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ChartCurrencyToggle } from './chart-currency-toggle';

describe('ChartCurrencyToggle', () => {
    it('renders user currency before account currency', () => {
        render(
            <ChartCurrencyToggle
                value="user"
                onValueChange={vi.fn()}
                userCurrencyCode="USD"
                accountCurrencyCode="EUR"
                showTooltip={false}
            />,
        );

        const buttons = screen.getAllByRole('radio');

        expect(buttons).toHaveLength(2);
        expect(buttons[0]).toHaveTextContent('USD');
        expect(buttons[1]).toHaveTextContent('EUR');
        expect(buttons[0]).toHaveAttribute('data-state', 'on');
    });
});
