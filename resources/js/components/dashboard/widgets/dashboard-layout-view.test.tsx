import { type DashboardProps } from '@/types/dashboard';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DashboardLayoutView } from './dashboard-layout-view';
import { SYSTEM_DASHBOARD_LAYOUT } from './registry';
import { type SystemWidgetProps } from './types';

function stub(id: string) {
    return ({ size }: SystemWidgetProps) => (
        <section data-testid="widget" data-widget={id}>
            {size.width} {size.height}
        </section>
    );
}

vi.mock('./system-widgets', () => ({
    NetWorthWidget: stub('net-worth'),
    MonthlySavingsWidget: stub('monthly-savings'),
    TopCategoriesWidget: stub('top-categories'),
    TopLabelsWidget: stub('top-labels'),
    CashflowWidget: stub('cashflow'),
}));

vi.mock('./account-balances-widget', () => ({
    AccountBalancesWidget: stub('account-balances'),
}));

describe('DashboardLayoutView', () => {
    it('draws every widget of the layout, in order, at its size', () => {
        render(
            <DashboardLayoutView
                layout={SYSTEM_DASHBOARD_LAYOUT}
                data={{} as DashboardProps}
            />,
        );

        const widgets = screen.getAllByTestId('widget');

        expect(widgets.map((el) => el.dataset.widget)).toEqual(
            SYSTEM_DASHBOARD_LAYOUT.map((item) => item.widget),
        );
        expect(widgets[0].textContent).toBe('1/1 l');
    });

    it('draws only what the layout lists', () => {
        render(
            <DashboardLayoutView
                layout={[
                    { widget: 'cashflow', size: { width: '1/2', height: 'm' } },
                ]}
                data={{} as DashboardProps}
            />,
        );

        expect(
            screen.getAllByTestId('widget').map((el) => el.dataset.widget),
        ).toEqual(['cashflow']);
    });
});
