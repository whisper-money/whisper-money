import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { MonthlySavingsDashboardCard } from './monthly-savings-dashboard-card';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    index: () => ({ url: '/budgets' }),
}));

vi.mock('@/actions/App/Http/Controllers/SavingsGoalController', () => ({
    show: ({ savingsGoal }: { savingsGoal: string }) => ({
        url: `/savings-goals/${savingsGoal}`,
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

function month(key: string, saved: number, target: number, status: string) {
    return {
        month: key,
        target_type: 'amount',
        target_amount: target,
        target_rate: null,
        income_base: null,
        is_live_target: false,
        target,
        saved,
        difference: saved - target,
        status,
    };
}

function goal(
    id: string,
    name: string,
    saved: number,
    target: number,
    lastMonth: string,
) {
    return {
        id,
        name,
        kind: 'monthly',
        archived_at: null,
        monthly: {
            current: {
                ...month('2026-10', saved, target, 'in_progress'),
                remaining: Math.max(0, target - saved),
                days_left: 22,
            },
            history: [
                month('2026-09', 0, target, lastMonth),
                month('2026-10', saved, target, 'in_progress'),
            ],
        },
    } as never;
}

describe('MonthlySavingsDashboardCard', () => {
    it('adds this month up across goals and links each one', () => {
        render(
            <MonthlySavingsDashboardCard
                goals={[
                    goal('a', 'Emergency fund', 12000, 30000, 'met'),
                    goal('b', 'Japan trip', 5000, 20000, 'missed'),
                ]}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('17000')).toBeInTheDocument();
        expect(screen.getByText('50000')).toBeInTheDocument();
        expect(screen.getByText(/22 days left/)).toBeInTheDocument();
        expect(screen.getByText(/September: 1 of 2 met/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Japan trip' }),
        ).toHaveAttribute('href', '/savings-goals/b');
        expect(screen.getByRole('link', { name: 'See all' })).toHaveAttribute(
            'href',
            '/budgets',
        );
    });

    it('leaves out a goal whose month has not opened yet', () => {
        const unopened = {
            id: 'c',
            name: 'Not yet',
            kind: 'monthly',
            archived_at: null,
            monthly: { current: null, history: [] },
        } as never;

        const { container } = render(
            <MonthlySavingsDashboardCard
                goals={[unopened]}
                currencyCode="EUR"
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws nothing without a monthly goal', () => {
        const { container } = render(
            <MonthlySavingsDashboardCard goals={[]} currencyCode="EUR" />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
