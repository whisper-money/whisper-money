import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import {
    TopCategoriesCard,
    type TopCategoriesData,
} from './top-categories-card';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-format-amount', () => ({
    useFormatAmount: () => ({
        formatAmount: (amount: number) => `€${amount}`,
    }),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

vi.mock('@/components/privacy-reveal-button', () => ({
    PrivacyRevealButton: () => null,
}));

vi.mock('@/components/categories/category-analysis-button', () => ({
    CategoryAnalysisButton: () => null,
}));

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    index: ({ query }: { query: Record<string, string> }) => ({
        url: `/transactions?${new URLSearchParams(query).toString()}`,
    }),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { user: { currency_code: 'EUR' } } },
    }),
    Link: ({
        children,
        href,
        onClick,
    }: {
        children: React.ReactNode;
        href: string;
        onClick?: () => void;
    }) => (
        <a
            href={href}
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

function category(id: string, name: string, amount: number, total: number) {
    return {
        category: { id, name, color: 'blue', icon: 'Utensils' } as never,
        category_id: id,
        amount,
        previous_amount: 0,
        total_amount: total,
        has_children: false,
        is_direct: false,
    };
}

function data(overrides: Partial<TopCategoriesData> = {}): TopCategoriesData {
    return {
        period: { from: '2026-11-01', to: '2026-11-30', through: '2026-11-24' },
        total: 164100,
        categories: [
            category('home', 'Home services', 85000, 164100),
            category('food', 'Food', 31200, 164100),
        ],
        uncategorized: { amount: 47900, count: 31 },
        leaves_out_estimates: false,
        ...overrides,
    };
}

describe('TopCategoriesCard', () => {
    it('reports the month so far and its total', () => {
        render(<TopCategoriesCard data={data()} />);

        expect(screen.getByText('Spending this month')).toBeInTheDocument();
        expect(screen.getByText(/Nov 1\s*–\s*24/)).toBeInTheDocument();
        expect(screen.getByText('164100')).toBeInTheDocument();
    });

    it('shows the uncategorized spending as a row linking to those movements', () => {
        render(<TopCategoriesCard data={data()} />);

        expect(screen.getByText('31 movements')).toBeInTheDocument();
        expect(screen.getByText('47900')).toBeInTheDocument();
        expect(screen.getByTestId('uncategorized-bar')).toBeInTheDocument();

        const link = screen.getByRole('link', { name: 'Categorize these' });
        const params = new URL(link.getAttribute('href')!, 'http://localhost')
            .searchParams;
        expect(params.get('category_ids')).toBe('uncategorized');
        expect(params.get('date_from')).toBe('2026-11-01');
        expect(params.get('date_to')).toBe('2026-11-30');
        expect(params.get('amount_max')).toBe('0');
    });

    it('says the rows add up to the total only when they do', () => {
        const { rerender } = render(<TopCategoriesCard data={data()} />);

        expect(
            screen.getByText(
                'Same period as Cashflow, so the rows add up to €164100.',
            ),
        ).toBeInTheDocument();

        rerender(<TopCategoriesCard data={data({ total: 200000 })} />);

        expect(screen.queryByText(/rows add up/)).not.toBeInTheDocument();

        rerender(
            <TopCategoriesCard data={data({ leaves_out_estimates: true })} />,
        );

        expect(screen.queryByText(/rows add up/)).not.toBeInTheDocument();
    });

    it('leaves the uncategorized row out when every movement has a category', () => {
        render(
            <TopCategoriesCard
                data={data({
                    total: 116200,
                    uncategorized: { amount: 0, count: 0 },
                })}
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'Categorize these' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/rows add up/)).toBeInTheDocument();
    });

    it('counts a single movement in the singular', () => {
        render(
            <TopCategoriesCard
                data={data({ uncategorized: { amount: 500, count: 1 } })}
            />,
        );

        expect(screen.getByText('1 movement')).toBeInTheDocument();
    });

    it('shows the empty state with nothing spent', () => {
        render(
            <TopCategoriesCard
                data={data({
                    total: 0,
                    categories: [],
                    uncategorized: { amount: 0, count: 0 },
                })}
            />,
        );

        expect(
            screen.getByText('No spending data this month'),
        ).toBeInTheDocument();
    });

    it('reports the click on "Categorize these" with how many are uncategorized', () => {
        captureEvent.mockReset();
        render(<TopCategoriesCard data={data()} />);

        fireEvent.click(screen.getByRole('link', { name: 'Categorize these' }));

        expect(captureEvent.mock.calls).toEqual([
            [
                'dashboard_uncategorized_categorize_clicked',
                { uncategorized_count: 31 },
            ],
        ]);
    });
});
