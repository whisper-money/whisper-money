import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import NotificationsPage from './notifications';

const page = vi.hoisted(() => ({ features: { forecast: false } }));
const patch = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => ({ props: { features: page.features } }),
    router: { patch },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

const props = {
    notifyAchievements: true,
    notifyMarketing: true,
    notifyOnBankTransactionsSynced: true,
    notifyOnInactiveNoBank: true,
    notifyMonthlySummary: true,
    budgetDefaults: {
        notify_on_new_transaction: false,
        notify_on_close_to_limit: true,
        notify_on_over_limit: true,
    },
    budgets: [],
    savingsGoalReminderDefault: true,
    monthlySavingsGoals: [],
};

describe('Notifications settings page', () => {
    beforeEach(() => {
        patch.mockClear();
        page.features = { forecast: false };
    });

    it('has no forecast alerts toggle with the Forecast flag off', () => {
        render(<NotificationsPage {...props} />);

        expect(
            screen.queryByLabelText('Forecast alerts by email'),
        ).not.toBeInTheDocument();
    });

    it('turns forecast alert emails off with the Forecast flag on', () => {
        page.features = { forecast: true };
        render(<NotificationsPage {...props} notifyForecastAlerts />);

        const toggle = screen.getByLabelText('Forecast alerts by email');
        expect(toggle).toBeChecked();

        fireEvent.click(toggle);

        expect(patch).toHaveBeenCalledWith(
            expect.any(String),
            { notifications: { forecast_alert_email: false } },
            expect.anything(),
        );
    });
});
