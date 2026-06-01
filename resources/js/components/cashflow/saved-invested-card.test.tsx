import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SavedInvestedCard } from './saved-invested-card';

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
    HelpCircle: () => <svg data-testid="help-icon" />,
    TrendingDown: () => <svg data-testid="comparison-trend-down" />,
    TrendingUp: () => <svg data-testid="comparison-trend-up" />,
}));

describe('SavedInvestedCard', () => {
    it('shows the saved+invested total, its share of net, and previous period comparisons', () => {
        render(
            <SavedInvestedCard
                current={summary(200000, 120000, 80000, 40, 30000, 10000)}
                previous={summary(100000, 70000, 30000, 30, 9000, 3000)}
                currency="EUR"
            />,
        );

        // allocated (30000 + 10000) over net 80000 = 50%
        expect(screen.getByText('40000')).toBeTruthy();
        expect(screen.getByText('50.0%')).toBeTruthy();
        // share moved from 40% to 50%
        expect(screen.getByText('+10.0%')).toBeTruthy();
        expect(screen.getByText('28000')).toBeTruthy();
        // saved and invested breakdown
        expect(screen.getByText('30000')).toBeTruthy();
        expect(screen.getByText('21000')).toBeTruthy();
        expect(screen.getByText('10000')).toBeTruthy();
        expect(screen.getByText('7000')).toBeTruthy();
        expect(screen.getAllByText('vs last period')).toHaveLength(4);
        expect(screen.getAllByTestId('comparison-trend-up')).toHaveLength(4);
        expect(screen.queryByTestId('comparison-trend-down')).toBeNull();
    });

    it('reveals where the numbers come from when the help icon is clicked', () => {
        render(
            <SavedInvestedCard
                current={summary(200000, 120000, 80000, 40, 30000, 10000)}
                previous={summary(100000, 70000, 30000, 30, 9000, 3000)}
                currency="EUR"
            />,
        );

        expect(screen.queryByText(/transactions categorized/i)).toBeNull();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Where do these numbers come from?',
            }),
        );

        expect(screen.getByText(/transactions categorized/i)).toBeTruthy();
    });

    it('hides comparisons when there is no previous period data', () => {
        render(
            <SavedInvestedCard
                current={summary(200000, 120000, 80000, 40, 30000, 10000)}
                previous={summary(0, 0, 0, 0, 0, 0)}
                currency="EUR"
            />,
        );

        expect(screen.getByText('50.0%')).toBeTruthy();
        expect(screen.getByText('40000')).toBeTruthy();
        expect(screen.queryByText('vs last period')).toBeNull();
        expect(screen.queryByTestId('comparison-trend-up')).toBeNull();
        expect(screen.queryByTestId('comparison-trend-down')).toBeNull();
    });
});
