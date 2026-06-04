import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BreakdownCard } from './breakdown-card';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    index: () => ({ url: '/transactions' }),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props: { chartColorScheme: 'colorful' } }),
}));

describe('BreakdownCard', () => {
    it('renders uncategorized rows when the API returns a null category', () => {
        render(
            <BreakdownCard
                type="expense"
                data={{
                    data: [
                        {
                            category: null,
                            category_id: null,
                            amount: 12345,
                            percentage: 100,
                            previous_amount: 0,
                        },
                    ],
                    total: 12345,
                    previous_total: 0,
                }}
                currency="USD"
            />,
        );

        expect(screen.getByText('Uncategorized')).toBeInTheDocument();
        expect(screen.getByText('100%')).toBeInTheDocument();
    });

    it('expands a parent category and loads its children on demand', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            json: async () => ({
                data: [
                    {
                        category: {
                            id: 'child-1',
                            name: 'Groceries',
                            icon: 'ShoppingCart',
                            color: 'blue',
                            type: 'expense',
                            cashflow_direction: 'outflow',
                            parent_id: 'parent-1',
                        },
                        category_id: 'child-1',
                        amount: 4000,
                        percentage: 100,
                        previous_amount: 0,
                        has_children: false,
                        is_direct: false,
                    },
                ],
                total: 4000,
                previous_total: 0,
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <BreakdownCard
                type="expense"
                data={{
                    data: [
                        {
                            category: {
                                id: 'parent-1',
                                name: 'Food',
                                icon: 'Utensils',
                                color: 'amber',
                                type: 'expense',
                                cashflow_direction: 'outflow',
                                parent_id: null,
                            },
                            category_id: 'parent-1',
                            amount: 4000,
                            percentage: 100,
                            previous_amount: 0,
                            has_children: true,
                            is_direct: false,
                        },
                    ],
                    total: 4000,
                    previous_total: 0,
                }}
                currency="USD"
                period={{
                    from: new Date('2026-06-01'),
                    to: new Date('2026-06-30'),
                }}
            />,
        );

        expect(screen.queryByText('Groceries')).not.toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Show subcategories' }),
        );

        await waitFor(() =>
            expect(screen.getByText('Groceries')).toBeInTheDocument(),
        );
        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining('parent=parent-1'),
        );
        expect(
            screen.getByRole('button', { name: 'Hide subcategories' }),
        ).toBeInTheDocument();
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
});
