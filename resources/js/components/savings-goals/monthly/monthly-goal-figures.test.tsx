import { MonthlySavingsCurrent } from '@/types/savings-goal';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CurrentMonthProgress } from './monthly-goal-figures';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

function current(
    overrides: Partial<MonthlySavingsCurrent>,
): MonthlySavingsCurrent {
    return {
        month: '2026-10',
        target_type: 'amount',
        target_amount: 30000,
        target_rate: null,
        income_base: null,
        is_live_target: false,
        target: 30000,
        saved: 12000,
        difference: -18000,
        status: 'in_progress',
        remaining: 18000,
        days_left: 22,
        ...overrides,
    };
}

describe('CurrentMonthProgress', () => {
    it('counts down to the target in a month in progress', () => {
        render(
            <CurrentMonthProgress current={current({})} currencyCode="EUR" />,
        );

        expect(screen.getByText(/to go/)).toBeTruthy();
        expect(screen.getByText(/22 days left/)).toBeTruthy();
    });

    it('shows only what was saved in a partial month', () => {
        render(
            <CurrentMonthProgress
                current={current({ status: 'partial', saved: 5000 })}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('5000')).toBeTruthy();
        expect(screen.getByText(/^Partial month/)).toBeTruthy();
        expect(screen.queryByText(/to go/)).toBeNull();
        expect(screen.queryByText(/days left/)).toBeNull();
    });

    it('explains an empty share-of-income target instead of calling it met', () => {
        render(
            <CurrentMonthProgress
                current={current({
                    target_type: 'income_rate',
                    target_rate: 20,
                    is_live_target: true,
                    target: 0,
                    saved: 0,
                    difference: 0,
                    remaining: 0,
                })}
                currencyCode="EUR"
            />,
        );

        expect(
            screen.getByText('the target grows as income comes in'),
        ).toBeTruthy();
        expect(screen.queryByText(/Target met/)).toBeNull();
    });
});
