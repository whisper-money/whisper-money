import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
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
});
