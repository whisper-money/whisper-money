import { type ForecastAccountTab } from '@/types/forecast';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ForecastAccountTabs } from './forecast-account-tabs';

const { router, editor } = vi.hoisted(() => ({
    router: { visit: vi.fn() },
    editor: { current: null as { open: ReturnType<typeof vi.fn> } | null },
}));

vi.mock('@inertiajs/react', () => ({ router }));

vi.mock('@/components/forecast/forecast-editor', () => ({
    useForecastEditor: () => editor.current,
}));

vi.mock('@/hooks/use-format-amount', () => ({
    useFormatAmount: () => ({
        formatAmount: (amount: number) => `€${amount / 100}`,
    }),
}));

function account(
    overrides: Partial<ForecastAccountTab> & Pick<ForecastAccountTab, 'id'>,
): ForecastAccountTab {
    return {
        name: overrides.id,
        type: 'checking',
        currencyCode: 'EUR',
        projectionOnly: false,
        trend: null,
        hasAlert: false,
        ...overrides,
    };
}

const ACCOUNTS = [
    account({ id: 'payroll', name: 'Payroll', hasAlert: true }),
    account({ id: 'daily', name: 'Daily' }),
    account({
        id: 'savings',
        name: 'Savings box',
        type: 'savings',
        trend: { monthly: 12000, band: 1000, points: 12 },
    }),
    account({
        id: 'index',
        name: 'Index funds',
        type: 'investment',
        projectionOnly: true,
    }),
];

function trigger(): HTMLElement {
    return document.querySelector('[data-test="forecast-account-filter"]')!;
}

function openMenu(): HTMLElement {
    fireEvent.pointerDown(trigger(), { button: 0, ctrlKey: false });

    return screen.getByRole('menu');
}

function option(id: string): HTMLElement {
    return document.querySelector(`[data-test="forecast-tab-${id}"]`)!;
}

describe('ForecastAccountTabs', () => {
    beforeEach(() => {
        router.visit.mockClear();
        editor.current = null;
    });

    it('shows "All accounts" with how many accounts warn, and lists them grouped', () => {
        render(
            <ForecastAccountTabs
                accounts={ACCOUNTS}
                selectedId={null}
                horizon={12}
            />,
        );

        expect(trigger()).toHaveTextContent('All accounts1 warning');

        const menu = openMenu();
        expect(option('all')).toHaveTextContent(
            'All accounts4 accounts · 1 with a warning',
        );
        expect(option('all')).toHaveAttribute('aria-checked', 'true');
        expect(within(menu).getByText('Your accounts')).toBeInTheDocument();
        expect(within(menu).getByText('Projection only')).toBeInTheDocument();
        expect(option('payroll')).toHaveTextContent('PayrollCheckingAlert');
        expect(
            within(option('payroll')).getByRole('img', {
                name: 'has a warning',
            }),
        ).toBeInTheDocument();
        expect(option('savings')).toHaveTextContent(
            'Savings · trend +€120/month',
        );
        expect(option('index')).toHaveTextContent(
            'Investment · projection only',
        );
        expect(
            document.querySelector('[data-test="forecast-choose-accounts"]'),
        ).toBeNull();
    });

    it('shows the selected account, with its warning dot, and marks it in the menu', () => {
        render(
            <ForecastAccountTabs
                accounts={ACCOUNTS}
                selectedId="payroll"
                horizon={6}
            />,
        );

        expect(trigger()).toHaveTextContent('Payroll');
        expect(
            within(trigger()).getByRole('img', { name: 'has a warning' }),
        ).toBeInTheDocument();
        expect(
            document.querySelector(
                '[data-test="forecast-account-filter-alerts"]',
            ),
        ).toBeNull();

        openMenu();
        expect(option('payroll')).toHaveAttribute('aria-checked', 'true');
        expect(option('all')).toHaveAttribute('aria-checked', 'false');

        fireEvent.click(option('payroll'));
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('goes to the account picked, keeping the horizon and the scroll', () => {
        render(
            <ForecastAccountTabs
                accounts={ACCOUNTS}
                selectedId={null}
                horizon={3}
            />,
        );

        openMenu();
        fireEvent.click(option('daily'));

        expect(router.visit).toHaveBeenCalledWith(
            '/forecast?account=daily&horizon=3',
            { preserveScroll: true },
        );
    });

    it('opens the accounts editor from the menu when the forecast is editable', () => {
        editor.current = { open: vi.fn() };
        render(
            <ForecastAccountTabs
                accounts={ACCOUNTS.slice(0, 2)}
                selectedId="daily"
                horizon={12}
            />,
        );

        const menu = openMenu();
        expect(within(menu).queryByText('Projection only')).toBeNull();
        expect(option('all')).toHaveTextContent(
            '2 accounts · 1 with a warning',
        );

        fireEvent.click(
            document.querySelector('[data-test="forecast-choose-accounts"]')!,
        );

        expect(editor.current.open).toHaveBeenCalledWith({ kind: 'accounts' });
        expect(router.visit).not.toHaveBeenCalled();
    });
});
