import { render, screen } from '@testing-library/react';
import * as React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { ChartContainer, ChartTooltipContent } from './chart';
import type { ChartConfig } from './chart';

// ResponsiveContainer measures its DOM parent, which is 0x0 in jsdom, so it
// would render nothing. The tooltip needs the chart config context around it,
// not a laid-out chart.
vi.mock('recharts', async (importOriginal) => {
    const actual = (await importOriginal()) as typeof import('recharts');
    return {
        ...actual,
        ResponsiveContainer: ({ children }: { children: React.ReactNode }) => (
            <>{children}</>
        ),
    };
});

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode: vi.fn(),
        setPrivacyMode: vi.fn(),
    }),
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en',
}));

const config: ChartConfig = {
    checking: { label: 'Checking' },
    savings: { label: 'Savings' },
    cash: { label: 'Cash' },
};

/** Amounts are in minor units, like the chart data itself. */
function item(id: string, value: number, display?: number) {
    return {
        dataKey: id,
        name: id,
        value,
        payload: display === undefined ? {} : { [`${id}_display`]: display },
    };
}

function renderTooltip(props: Partial<
    React.ComponentProps<typeof ChartTooltipContent>
>) {
    return render(
        <ChartContainer config={config}>
            <ChartTooltipContent
                active
                hideLabel
                displayCurrency="EUR"
                payload={[
                    item('checking', 150000),
                    item('savings', 0),
                    item('cash', 20000),
                ]}
                {...props}
            />
        </ChartContainer>,
    );
}

function totalRow(): string {
    return screen.getByText('Total').parentElement?.textContent ?? '';
}

describe('ChartTooltipContent zero rows', () => {
    it('drops the accounts worth nothing at the hovered point', () => {
        renderTooltip({ hideZeroValues: true });

        expect(screen.getByText('Checking')).toBeInTheDocument();
        expect(screen.getByText('Cash')).toBeInTheDocument();
        expect(screen.queryByText('Savings')).not.toBeInTheDocument();
    });

    it('keeps the same account at a point where it holds something', () => {
        renderTooltip({
            hideZeroValues: true,
            payload: [
                item('checking', 150000),
                item('savings', 90000),
                item('cash', 20000),
            ],
        });

        expect(screen.getByText('Savings')).toBeInTheDocument();
    });

    it('leaves zero rows alone for the charts that do not opt in', () => {
        renderTooltip({});

        expect(screen.getByText('Savings')).toBeInTheDocument();
    });

    it('reads the same total either way', () => {
        const { unmount } = renderTooltip({});
        const before = totalRow();

        unmount();
        renderTooltip({ hideZeroValues: true });

        expect(totalRow()).toBe(before);
    });

    // Net worth mode scales the asset series to fit the bar, and a month with a
    // negative net worth scales every one of them to 0. The rows must survive:
    // the balance they show is the unscaled `_display` value.
    it('judges a scaled net-worth series by the value it displays', () => {
        renderTooltip({
            hideZeroValues: true,
            netWorthMode: { liabilityTypeLabel: 'Loan' },
            payload: [
                item('checking', 0, 150000),
                item('savings', 0, 0),
                item('cash', 0, 20000),
            ],
        });

        expect(screen.getByText('Checking')).toBeInTheDocument();
        expect(screen.getByText('Cash')).toBeInTheDocument();
        expect(screen.queryByText('Savings')).not.toBeInTheDocument();
    });
});
