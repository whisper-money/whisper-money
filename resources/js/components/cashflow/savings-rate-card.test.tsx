import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SavingsRateCard } from './savings-rate-card';

const summary = (
    income: number,
    expense: number,
    net: number,
    savingsRate: number,
    savings: number,
    investments: number,
) => ({
    income,
    expense,
    net,
    savings_rate: savingsRate,
    savings,
    investments,
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

describe('SavingsRateCard', () => {
    it('shows previous period comparisons below rate, saved, and invested values', () => {
        render(
            <SavingsRateCard
                current={summary(200000, 120000, 80000, 40, 50000, 30000)}
                previous={summary(100000, 70000, 30000, 30, 40000, 50000)}
                currency="EUR"
            />,
        );

        expect(screen.getByText('40.0%')).toBeTruthy();
        expect(screen.getByText('+10.0%')).toBeTruthy();
        expect(screen.getByText('50000')).toBeTruthy();
        expect(screen.getByText('30000')).toBeTruthy();
        expect(screen.getByText('10000')).toBeTruthy();
        expect(screen.getByText('-20000')).toBeTruthy();
        expect(screen.getAllByText('vs last period')).toHaveLength(3);
        expect(screen.getAllByTestId('comparison-trend-up')).toHaveLength(2);
        expect(screen.getAllByTestId('comparison-trend-down')).toHaveLength(1);
    });

    it('hides comparisons when there is no previous period data', () => {
        render(
            <SavingsRateCard
                current={summary(200000, 120000, 80000, 40, 50000, 30000)}
                previous={summary(0, 0, 0, 0, 0, 0)}
                currency="EUR"
            />,
        );

        expect(screen.queryByText('vs last period')).toBeNull();
        expect(screen.queryByTestId('comparison-trend-up')).toBeNull();
        expect(screen.queryByTestId('comparison-trend-down')).toBeNull();
    });
});
