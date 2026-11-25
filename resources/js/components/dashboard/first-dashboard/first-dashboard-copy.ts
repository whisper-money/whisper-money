import { type FirstDashboardState } from '@/types/dashboard';
import {
    formatDayFromDate,
    formatMonthName,
    previousYearMonth,
} from '@/utils/date';
import { __ } from '@/utils/i18n';

/**
 * The line under the dashboard's title while there is no real movement:
 * "Tracking since today · 1 account · last month in round figures". Days are
 * compared on the server's reading of the user's calendar, not the browser's.
 */
export function firstDashboardDescription(
    state: FirstDashboardState,
    locale: string,
): string {
    const since =
        state.tracking_since === state.today
            ? __('Tracking since today')
            : __('Tracking since :date', {
                  date: formatDayFromDate(state.tracking_since, locale),
              });

    const accounts =
        state.accounts_count === 1
            ? __('1 account')
            : __(':count accounts', { count: state.accounts_count });

    return [since, accounts, movementsSummary(state, locale)].join(' · ');
}

/** What stands in for movements: none, or a month in round figures. */
function movementsSummary(state: FirstDashboardState, locale: string): string {
    if (state.state === 'balances') {
        return __('no movements yet');
    }

    const { month } = state.estimates;

    return month === previousYearMonth(state.today.slice(0, 7))
        ? __('last month in round figures')
        : __(':month in round figures', {
              month: formatMonthName(month, locale),
          });
}
