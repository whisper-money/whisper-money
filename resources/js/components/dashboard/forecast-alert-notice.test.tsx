import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { type ForecastDashboardAlertData } from '@/types/forecast-entry';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ForecastAlertNotice } from './forecast-alert-notice';

const post = vi.fn();

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@inertiajs/react', async () => ({
    Link: (await import('@/lib/inertia-link.fixture')).FixtureLink,
    useHttp: () => ({ post }),
}));

function alert(
    overrides: Partial<ForecastDashboardAlertData> = {},
): ForecastDashboardAlertData {
    return {
        id: 'alert-1',
        accountId: 'account-1',
        accountName: 'Checking',
        currencyCode: 'EUR',
        month: '2027-04',
        lowestBalance: -15500,
        lowestOn: '2027-04-27',
        causes: [
            { name: 'car insurance', amount: 70000 },
            { name: 'property tax', amount: 40000 },
        ],
        remindOn: '2027-03-01',
        ...overrides,
    };
}

function renderNotice(data: ForecastDashboardAlertData) {
    return render(
        <PrivacyModeProvider>
            <ForecastAlertNotice alert={data} />
        </PrivacyModeProvider>,
    );
}

describe('ForecastAlertNotice', () => {
    beforeEach(() => {
        post.mockReset();
    });

    it('says which month runs short, how low and why', () => {
        renderNotice(alert());

        expect(
            screen.getByText('April looks tight in your Checking'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /You could drop to .*155 around 27 April because of car insurance and property tax\./,
            ),
        ).toBeInTheDocument();
    });

    it('leaves the causes out when there are none', () => {
        renderNotice(alert({ causes: [] }));

        expect(
            screen.getByText(/You could drop to .*155 around 27 April\.$/),
        ).toBeInTheDocument();
    });

    it('falls back to a general line without the lowest balance', () => {
        renderNotice(alert({ lowestBalance: null, lowestOn: null }));

        expect(
            screen.getByText(
                'Your balance may not stretch that far that month.',
            ),
        ).toBeInTheDocument();
    });

    it('links to the forecast of the account', () => {
        renderNotice(alert());

        expect(
            screen.getByRole('link', { name: 'See forecast' }),
        ).toHaveAttribute('href', '/forecast?account=account-1');
    });

    it('goes away and snoozes the alert when asked to remind later', () => {
        renderNotice(alert());

        fireEvent.click(
            screen.getByRole('button', { name: 'Remind me in March' }),
        );

        expect(post).toHaveBeenCalledWith('/forecast/alerts/alert-1/snooze');
        expect(
            screen.queryByText('April looks tight in your Checking'),
        ).not.toBeInTheDocument();
    });

    it('offers no reminder when it would already be due', () => {
        renderNotice(alert({ remindOn: null }));

        expect(
            screen.queryByRole('button', { name: /Remind me/ }),
        ).not.toBeInTheDocument();
    });
});
