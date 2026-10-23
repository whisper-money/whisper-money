import {
    CashflowBudgetMargin,
    CashflowSummary,
} from '@/hooks/use-cashflow-data';
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CashflowDetailsDrawer } from './cashflow-details-drawer';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

function budget(
    name: string,
    expenseType: 'fixed' | 'variable',
    allocated: number,
    spent: number,
): CashflowBudgetMargin {
    return {
        budget_id: name,
        budget_period_id: `${name}-period`,
        name,
        start_date: '2026-10-01',
        end_date: '2026-10-31',
        expense_type: expenseType,
        allocated,
        spent,
        margin: allocated - spent,
    };
}

function summary(overrides: Partial<CashflowSummary> = {}): CashflowSummary {
    const income = overrides.income ?? 200000;
    const expense = overrides.expense ?? 132000;

    return {
        income,
        expense,
        net: income - expense,
        savings_rate: 0,
        savings: 25000,
        investments: 25000,
        fixed_expense: 90000,
        variable_expense: 30000,
        general_expense: 12000,
        reserved: 13000,
        budgets: [
            budget('Rent', 'fixed', 90000, 90000),
            budget('Groceries', 'variable', 40000, 30000),
            budget('Leisure', 'variable', 10000, 7000),
            budget('Gym', 'variable', 5000, 8000),
        ],
        ...overrides,
    };
}

const previous = summary({
    income: 190000,
    expense: 120000,
    fixed_expense: 85000,
    variable_expense: 25000,
    general_expense: 10000,
    savings: 20000,
    investments: 30000,
    reserved: undefined,
    budgets: undefined,
});

function renderDrawer(
    props: Partial<Parameters<typeof CashflowDetailsDrawer>[0]> = {},
) {
    return render(
        <CashflowDetailsDrawer
            open
            onOpenChange={vi.fn()}
            current={summary()}
            previous={previous}
            currency="EUR"
            periodType="month"
            periodLabel="October 2026"
            {...props}
        />,
    );
}

function tile(label: string) {
    const element = screen
        .getAllByText(label)
        .map((text) => text.closest<HTMLElement>('.rounded-lg'))
        .find((candidate) => candidate !== null);

    return within(element as HTMLElement);
}

function row(name: string): HTMLElement {
    return screen.getByRole('cell', { name }).closest('tr') as HTMLElement;
}

describe('CashflowDetailsDrawer', () => {
    it('titles the period and shows income, allocated, available and free', () => {
        renderDrawer();

        expect(
            screen.getByText('Where your money goes · October 2026'),
        ).toBeTruthy();
        // allocated 132000 + 50000; available 200000 − 182000; free 18000 − 13000
        expect(tile('Income').getByText('200000')).toBeTruthy();
        expect(tile('Allocated').getByText('182000')).toBeTruthy();
        expect(tile('Available').getByText('18000')).toBeTruthy();
        expect(tile('Free (cashflow)').getByText('5000')).toBeTruthy();
        expect(tile('Free (cashflow)').getByText('13000')).toBeTruthy();
    });

    it('splits the income into seven segments that fill the bar', () => {
        renderDrawer();

        const bar = screen.getByRole('img');
        const widths = Array.from(
            bar.querySelectorAll<HTMLElement>('[data-segment]'),
        ).map((segment) => [
            segment.dataset.segment,
            parseFloat(segment.style.width),
        ]);

        expect(widths.map(([key]) => key)).toEqual([
            'fixed',
            'variable',
            'general',
            'saved',
            'invested',
            'reserved',
            'free',
        ]);
        expect(
            widths.reduce((sum, [, width]) => sum + (width as number), 0),
        ).toBeCloseTo(100);
        expect(bar.getAttribute('aria-label')).toContain('Fixed 45%');
    });

    it('caps reserved at what was available and flags an overspend', () => {
        renderDrawer({
            current: summary({ income: 150000, reserved: 13000 }),
        });

        const bar = screen.getByRole('img');
        expect(bar.querySelector('[data-segment="reserved"]')).toBeNull();
        expect(bar.querySelector('[data-segment="free"]')).toBeNull();
        expect(
            screen.getByText('You allocated more than you earned:'),
        ).toBeTruthy();
    });

    it('draws an empty bar when nothing came in or went out', () => {
        renderDrawer({
            current: summary({
                income: 0,
                expense: 0,
                fixed_expense: 0,
                variable_expense: 0,
                general_expense: 0,
                savings: 0,
                investments: 0,
                reserved: 0,
                budgets: [],
            }),
        });

        expect(
            screen.getByRole('img').querySelector('[data-segment]'),
        ).toBeNull();
        expect(screen.getAllByText('0.0%')).toHaveLength(5);
    });

    it('keeps the bar empty when expenses came out of no income', () => {
        renderDrawer({ current: summary({ income: 0 }) });

        expect(
            screen.getByRole('img').querySelector('[data-segment]'),
        ).toBeNull();
        expect(screen.getByText('No income in this period')).toBeTruthy();
        expect(
            screen.queryByText('You allocated more than you earned:'),
        ).toBeNull();
    });

    it('reads a block that did not change as neither good nor bad', () => {
        renderDrawer({ previous: { ...summary(), budgets: undefined } });

        const fixed = within(row('Fixed'));
        expect(fixed.getByText('0')).toBeTruthy();
        expect(fixed.queryByText('+')).toBeNull();
    });

    it('lists every block with its share, amount and change', () => {
        renderDrawer();

        const fixed = within(row('Fixed'));
        expect(fixed.getByText('45.0%')).toBeTruthy();
        expect(fixed.getByText('90000')).toBeTruthy();
        expect(fixed.getByText('5000')).toBeTruthy();

        expect(within(row('Invested')).getByText('-5000')).toBeTruthy();
        expect(screen.queryAllByRole('link')).toHaveLength(0);
    });

    it('leaves the change out without a previous period', () => {
        renderDrawer({
            previous: summary({
                income: 0,
                expense: 0,
                fixed_expense: 0,
                variable_expense: 0,
                general_expense: 0,
            }),
        });

        expect(within(row('Fixed')).getByText('—')).toBeTruthy();
    });

    it('links a block to its transactions only when given an href', () => {
        renderDrawer({ hrefs: { saved: '/transactions?block=saved' } });

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(1);
        expect(links[0].getAttribute('href')).toBe('/transactions?block=saved');
    });

    it('groups the budgets by expense type with what each group reserves', () => {
        renderDrawer();

        const fixed = within(
            screen
                .getByRole('heading', { name: 'Fixed budgets' })
                .closest('section') as HTMLElement,
        );
        expect(fixed.getByText('Rent')).toBeTruthy();
        expect(fixed.queryByText('Groceries')).toBeNull();

        const variable = within(
            screen
                .getByRole('heading', { name: 'Variable budgets' })
                .closest('section') as HTMLElement,
        );
        expect(variable.getByText('Groceries')).toBeTruthy();
        expect(variable.getByText('Gym')).toBeTruthy();
        // 10000 + 3000; the over-budget gym holds nothing back
        expect(variable.getByText('13000')).toBeTruthy();
        expect(variable.getByText('-3000')).toBeTruthy();
    });

    it('says so when a group has no budget in progress', () => {
        renderDrawer({ current: summary({ budgets: [] }) });

        expect(
            screen.getByText('No fixed budget in progress in this view.'),
        ).toBeTruthy();
        expect(
            screen.getByText('No variable budget in progress in this view.'),
        ).toBeTruthy();
    });

    it('points to the view that reserves the budgets this one leaves out', () => {
        const { rerender } = renderDrawer();

        expect(
            screen.getByText('Yearly budgets are reserved in the Year view'),
        ).toBeTruthy();

        rerender(
            <CashflowDetailsDrawer
                open
                onOpenChange={vi.fn()}
                current={summary()}
                previous={previous}
                currency="EUR"
                periodType="year"
                periodLabel="2026"
            />,
        );

        expect(
            screen.getByText(
                'Weekly and monthly budgets are reserved in the Month view',
            ),
        ).toBeTruthy();
    });
});
