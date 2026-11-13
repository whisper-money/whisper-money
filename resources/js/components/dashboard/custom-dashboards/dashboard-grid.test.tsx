import { type SystemWidgetProps } from '@/components/dashboard/widgets/types';
import { type DashboardProps } from '@/types/dashboard';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DashboardGrid } from './dashboard-grid';

function stub(id: string) {
    return ({ size }: SystemWidgetProps) => (
        <section data-testid="widget" data-stub={id}>
            {size.width} {size.height}
        </section>
    );
}

vi.mock('@/components/dashboard/widgets/system-widgets', () => ({
    NetWorthWidget: stub('net-worth'),
    MonthlySavingsWidget: () => null,
    TopCategoriesWidget: stub('top-categories'),
    TopLabelsWidget: stub('top-labels'),
    CashflowWidget: () => null,
}));

vi.mock('@/components/dashboard/widgets/account-balances-widget', () => ({
    AccountBalancesWidget: stub('account-balances'),
}));

function cellOf(widget: string): HTMLElement {
    const cell = document.querySelector<HTMLElement>(
        `[data-widget="${widget}"]`,
    );
    expect(cell).not.toBeNull();

    return cell!;
}

describe('DashboardGrid', () => {
    it('draws each widget in a cell spanning its size', () => {
        render(
            <DashboardGrid
                data={{} as DashboardProps}
                widgets={[
                    {
                        kind: 'system',
                        id: 'a',
                        widget: 'net-worth',
                        size: { width: '1/1', height: 'l' },
                    },
                    {
                        kind: 'system',
                        id: 'b',
                        widget: 'top-labels',
                        size: { width: '1/3', height: 'm' },
                    },
                ]}
            />,
        );

        expect(
            screen.getAllByTestId('widget').map((el) => el.dataset.stub),
        ).toEqual(['net-worth', 'top-labels']);
        expect(cellOf('net-worth').className).toContain('@2xl:row-span-2');
        expect(cellOf('top-labels').className).toContain('@4xl:col-span-4');
    });

    it('keeps no room for a widget that draws nothing', () => {
        render(
            <DashboardGrid
                data={{} as DashboardProps}
                widgets={[
                    {
                        kind: 'system',
                        id: 'a',
                        widget: 'cashflow',
                        size: { width: '1/2', height: 'm' },
                    },
                    {
                        kind: 'system',
                        id: 'b',
                        widget: 'monthly-savings',
                        size: { width: '1/3', height: 'm' },
                    },
                    {
                        kind: 'system',
                        id: 'c',
                        widget: 'top-labels',
                        size: { width: '1/2', height: 'm' },
                    },
                ]}
            />,
        );

        // An empty cell is hidden by `empty:hidden`, so it takes no grid slot.
        for (const widget of ['cashflow', 'monthly-savings']) {
            expect(cellOf(widget).childElementCount).toBe(0);
            expect(cellOf(widget).matches(':empty')).toBe(true);
            expect(cellOf(widget).className).toContain('empty:hidden');
        }
        expect(cellOf('top-labels').matches(':empty')).toBe(false);
    });
});
