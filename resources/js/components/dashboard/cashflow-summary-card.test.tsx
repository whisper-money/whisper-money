import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { type SpendingLimit } from '@/types/dashboard';
import { CashflowSummaryCard } from './cashflow-summary-card';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'en-US', auth: { user: { currency_code: 'EUR' } } },
    }),
    Link: ({
        children,
        href,
        className,
        onClick,
    }: {
        children: ReactNode;
        href: string;
        className?: string;
        onClick?: () => void;
    }) => (
        <a
            href={href}
            className={className}
            onClick={(event) => {
                event.preventDefault();
                onClick?.();
            }}
        >
            {children}
        </a>
    ),
}));

const captureEvent = vi.hoisted(() => vi.fn());

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/routes', () => ({ cashflow: () => ({ url: '/cashflow' }) }));

vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    show: ({ budget }: { budget: string }) => ({ url: `/budgets/${budget}` }),
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
    usePrivacyReveal: () => null,
}));

vi.mock('@/components/privacy-reveal-button', () => ({
    PrivacyRevealButton: () => null,
}));

const summary = {
    income: 215000,
    expense: 164100,
    net: 50900,
    savings_rate: 23.7,
};

function renderCard(spendingLimit: SpendingLimit | null) {
    return render(
        <CashflowSummaryCard
            data={{ current: summary, previous: summary }}
            spendingLimit={spendingLimit}
        />,
    );
}

function limit(overrides: Partial<SpendingLimit> = {}): SpendingLimit {
    return {
        budget_id: 'budget-1',
        allocated: 168400,
        spent: 164100,
        days_left: 6,
        ...overrides,
    };
}

function bar(): HTMLElement {
    return screen.getByRole('progressbar', { name: 'Monthly spending limit' })
        .firstElementChild as HTMLElement;
}

describe('CashflowSummaryCard spending limit', () => {
    it('shows what is left of the limit and links to its budget', () => {
        renderCard(limit());

        expect(screen.getByText('Monthly spending limit')).toBeInTheDocument();
        expect(
            screen.getByText('€43 left with 6 days to go'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Monthly spending limit').closest('a'),
        ).toHaveAttribute('href', '/budgets/budget-1');
    });

    it('turns the bar amber near the limit', () => {
        renderCard(limit());

        expect(bar()).toHaveClass('bg-warning');
    });

    it('keeps the bar neutral with room to spare', () => {
        renderCard(limit({ spent: 50000 }));

        expect(bar()).not.toHaveClass('bg-warning');
        expect(bar()).not.toHaveClass('bg-destructive');
    });

    it('says how far over the limit it went and turns the bar red', () => {
        renderCard(limit({ allocated: 100000, spent: 125000 }));

        expect(screen.getByText('€250 over the limit')).toBeInTheDocument();
        expect(bar()).toHaveClass('bg-destructive');
    });

    it('speaks of one day and of the last day', () => {
        const { rerender } = renderCard(limit({ days_left: 1 }));

        expect(
            screen.getByText('€43 left with 1 day to go'),
        ).toBeInTheDocument();

        rerender(
            <CashflowSummaryCard
                data={{ current: summary, previous: summary }}
                spendingLimit={limit({ days_left: 0 })}
            />,
        );

        expect(
            screen.getByText('€43 left on the last day'),
        ).toBeInTheDocument();
    });

    it('keeps the cents when less than one euro is left or over', () => {
        const { rerender } = renderCard(
            limit({ allocated: 100000, spent: 99960 }),
        );

        expect(
            screen.getByText('€0.40 left with 6 days to go'),
        ).toBeInTheDocument();

        rerender(
            <CashflowSummaryCard
                data={{ current: summary, previous: summary }}
                spendingLimit={limit({ allocated: 100000, spent: 100040 })}
            />,
        );

        expect(screen.getByText('€0.40 over the limit')).toBeInTheDocument();
    });

    it('shows nothing left in whole euros right at the limit', () => {
        renderCard(limit({ allocated: 100000, spent: 100000 }));

        expect(
            screen.getByText('€0 left with 6 days to go'),
        ).toBeInTheDocument();
    });

    it('keeps the bar neutral when a zero limit has nothing spent', () => {
        renderCard(limit({ allocated: 0, spent: 0 }));

        expect(bar()).not.toHaveClass('bg-destructive');
    });

    it('has no row without a spending limit', () => {
        renderCard(null);

        expect(
            screen.queryByText('Monthly spending limit'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });

    describe('spending limit click event', () => {
        beforeEach(() => captureEvent.mockReset());

        it.each([
            ['under', { spent: 50000 }],
            ['near', {}],
            ['over', { allocated: 100000, spent: 125000 }],
        ] as const)(
            'reports the row as %s the limit, with no amounts',
            (state, overrides) => {
                renderCard(limit(overrides));

                fireEvent.click(
                    screen.getByText('Monthly spending limit').closest('a')!,
                );

                expect(captureEvent.mock.calls).toEqual([
                    ['dashboard_spending_limit_clicked', { state }],
                ]);
            },
        );
    });
});
