import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AvailableCard } from './available-card';

vi.mock('lucide-react', () => ({
    TrendingDown: () => <svg />,
    TrendingUp: () => <svg />,
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

/** A period's summary; net is derived so it always matches income − expense. */
function summary(
    income: number,
    expense: number,
    savings: number,
    investments: number,
): CashflowSummary {
    return {
        net: income - expense,
        income,
        expense,
        savings,
        investments,
        savings_rate: 0,
    };
}

describe('AvailableCard', () => {
    it('shows income minus what was allocated, its share of income and the previous period', () => {
        render(
            <AvailableCard
                current={summary(200000, 132000, 25000, 25000)}
                previous={summary(190000, 120000, 25000, 26000)}
                currency="EUR"
            />,
        );

        // 200000 − (132000 + 25000 + 25000), in the headline and, with
        // nothing reserved, in Free too
        expect(screen.getAllByText('18000')).toHaveLength(2);
        expect(screen.getByText('9.0%')).toBeTruthy();
        // previous: 19000 over 190000 = 10%
        expect(screen.getByText('-1.0%')).toBeTruthy();
        expect(screen.getByText('-1000')).toBeTruthy();
        expect(screen.getByText('200000')).toBeTruthy();
        expect(screen.getByText('182000')).toBeTruthy();
        expect(screen.getByText('Expenses + contributed')).toBeTruthy();
        expect(screen.getAllByText('vs last period')).toHaveLength(4);
    });

    it('reads a period that spent more than it earned as negative', () => {
        render(
            <AvailableCard
                current={summary(100000, 90000, 20000, 0)}
                previous={summary(0, 0, 0, 0)}
                currency="EUR"
            />,
        );

        expect(screen.getAllByText('-10000')).toHaveLength(2);
        expect(screen.getByText('-10.0%')).toBeTruthy();
        expect(screen.queryByText('vs last period')).toBeNull();
    });

    it('reads 0% when there is no income', () => {
        render(
            <AvailableCard
                current={summary(0, 5000, 0, 0)}
                previous={summary(0, 0, 0, 0)}
                currency="EUR"
            />,
        );

        expect(screen.getByText('0.0%')).toBeTruthy();
    });

    it('highlights what is free once the open budgets are reserved', () => {
        render(
            <AvailableCard
                current={{
                    ...summary(200000, 132000, 25000, 25000),
                    reserved: 13000,
                }}
                previous={summary(0, 0, 0, 0)}
                currency="EUR"
            />,
        );

        expect(screen.getByText('Free (cashflow)')).toBeTruthy();
        // 18000 available − 13000 reserved
        expect(screen.getByText('5000')).toBeTruthy();
        expect(screen.getByText('13000')).toBeTruthy();
        expect(screen.getByText('reserved in budgets')).toBeTruthy();
    });

    it('reads everything available as free when nothing is reserved', () => {
        render(
            <AvailableCard
                current={{
                    ...summary(200000, 132000, 25000, 25000),
                    reserved: 0,
                }}
                previous={summary(0, 0, 0, 0)}
                currency="EUR"
            />,
        );

        // the headline and Free both read 18000
        expect(screen.getAllByText('18000')).toHaveLength(2);
        expect(screen.getByText('Nothing reserved in budgets')).toBeTruthy();
        expect(screen.queryByText('reserved in budgets')).toBeNull();
    });

    it('shows a placeholder while loading', () => {
        const { container } = render(
            <AvailableCard
                current={summary(0, 0, 0, 0)}
                previous={summary(0, 0, 0, 0)}
                loading
            />,
        );

        expect(screen.getByText('Available')).toBeTruthy();
        expect(container.querySelector('.animate-pulse')).toBeTruthy();
    });
});
