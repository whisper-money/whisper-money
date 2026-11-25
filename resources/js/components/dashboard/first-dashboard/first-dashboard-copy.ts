import { type FirstDashboardState } from '@/types/dashboard';
import {
    formatDayFromDate,
    formatMonthName,
    todayDateString,
} from '@/utils/date';
import { __ } from '@/utils/i18n';

/** The month today is in, as `Y-m`. */
export function currentYearMonth(): string {
    return todayDateString().slice(0, 7);
}

/** The month before `Y-m`, as `Y-m`. */
function previousYearMonth(yearMonth: string): string {
    const [year, month] = yearMonth.split('-').map(Number);
    const previous = new Date(year, month - 2, 1);

    return `${previous.getFullYear()}-${String(previous.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * The line under the dashboard's title while there is no real movement:
 * "Tracking since today · 1 account · last month in round figures".
 */
export function firstDashboardDescription(
    state: FirstDashboardState,
    locale: string,
): string {
    const since =
        state.tracking_since === todayDateString()
            ? __('Tracking since today')
            : __('Tracking since :date', {
                  date: formatDayFromDate(state.tracking_since, locale),
              });

    const accounts =
        state.accounts_count === 1
            ? __('1 account')
            : __(':count accounts', { count: state.accounts_count });

    return [since, accounts, whatThereIs(state, locale)].join(' · ');
}

function whatThereIs(state: FirstDashboardState, locale: string): string {
    if (state.state === 'balances') {
        return __('no movements yet');
    }

    const { month } = state.estimates;

    return month === previousYearMonth(currentYearMonth())
        ? __('last month in round figures')
        : __(':month in round figures', {
              month: formatMonthName(month, locale),
          });
}
