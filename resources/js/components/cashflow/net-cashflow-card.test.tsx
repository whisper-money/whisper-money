import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { NetCashflowCard } from './net-cashflow-card';

const summary = (
    income: number,
    expense: number,
    net: number,
    savingsRate = 0,
) => ({
    income,
    expense,
    net,
    savings_rate: savingsRate,
    savings: 0,
    investments: 0,
});

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('lucide-react', () => ({
    TrendingDown: () => <svg data-testid="comparison-trend-down" />,
    TrendingUp: () => <svg data-testid="comparison-trend-up" />,
}));

describe('NetCashflowCard', () => {
    it('only shows a trend arrow for the previous period comparison', () => {
        render(
            <NetCashflowCard
                current={summary(1200000, 337700, 862300, 71.9)}
                previous={summary(3000000, 261300, 2738700)}
                currency="EUR"
            />,
        );

        // net amount + income decreases; rate + expense increases
        expect(screen.getAllByTestId('comparison-trend-down')).toHaveLength(2);
        expect(screen.getAllByTestId('comparison-trend-up')).toHaveLength(2);
        expect(screen.getByText('-1876400')).toBeTruthy();
        expect(screen.getByText('-1800000')).toBeTruthy();
        expect(screen.getByText('76400')).toBeTruthy();
        expect(screen.getByText('71.9%')).toBeTruthy();
        expect(screen.getByText('+71.9%')).toBeTruthy();
    });

    it('keeps the signed net amount visible when current net cashflow is negative', () => {
        render(
            <NetCashflowCard
                current={summary(50000, 150000, -100000)}
                previous={summary(0, 0, 0)}
                currency="EUR"
            />,
        );

        expect(screen.queryByTestId('comparison-trend-down')).toBeNull();
        expect(screen.queryByTestId('comparison-trend-up')).toBeNull();
        expect(screen.getByText('-100000')).toBeTruthy();
    });
});
