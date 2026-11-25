import { type FirstDashboardState } from '@/types/dashboard';
import { formatMonthName, previousYearMonth } from '@/utils/date';
import { describe, expect, it } from 'vitest';
import { firstDashboardDescription } from './first-dashboard-copy';

const header = {
    today: '2026-11-25',
    tracking_since: '2026-11-25',
    accounts_count: 1,
    spending_guess: null,
    currency_code: 'EUR',
};

function estimates(month: string): FirstDashboardState {
    return {
        state: 'estimates',
        ...header,
        estimates: {
            month,
            count: 4,
            total: 169500,
            currency_code: 'EUR',
            account_id: 'account-1',
            rows: [],
        },
    };
}

describe('firstDashboardDescription', () => {
    it('says since today, one account and last month in round figures', () => {
        expect(firstDashboardDescription(estimates('2026-10'), 'en-US')).toBe(
            'Tracking since today · 1 account · last month in round figures',
        );
    });

    it('names the month when the estimates are not last month', () => {
        expect(firstDashboardDescription(estimates('2026-09'), 'en-US')).toBe(
            'Tracking since today · 1 account · September in round figures',
        );
    });

    it('says the day tracking started and that there are no movements', () => {
        expect(
            firstDashboardDescription(
                {
                    state: 'balances',
                    can_import: false,
                    ...header,
                    tracking_since: '2026-11-20',
                    accounts_count: 2,
                },
                'en-US',
            ),
        ).toBe('Tracking since Nov 20 · 2 accounts · no movements yet');
    });
});

describe('previousYearMonth', () => {
    it('steps back across a year', () => {
        expect(previousYearMonth('2026-11')).toBe('2026-10');
        expect(previousYearMonth('2027-01')).toBe('2026-12');
    });
});

describe('formatMonthName', () => {
    it("reads a month in full, in the reader's language", () => {
        expect(formatMonthName('2026-10', 'en-US')).toBe('October');
        expect(formatMonthName('2026-10', 'es-ES')).toBe('octubre');
    });
});
