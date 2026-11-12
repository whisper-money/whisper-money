import { type SystemWidgetId } from '@/components/dashboard/widgets/registry';
import {
    type WidgetHeight,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import { __ } from '@/utils/i18n';

/** The name the picker gives the dashboard every user has. */
export function systemDashboardName(): string {
    return __('Overview');
}

/** The name edit mode gives each system widget. */
export function systemWidgetLabel(id: SystemWidgetId): string {
    switch (id) {
        case 'net-worth':
            return __('Net Worth Evolution');
        case 'account-balances':
            return __('Accounts');
        case 'monthly-savings':
            return __('Monthly savings');
        case 'top-categories':
            return __('Top spending categories');
        case 'top-labels':
            return __('Top labels');
        case 'cashflow':
            return __('Cashflow');
    }
}

export function widgetWidthLabel(width: WidgetWidth): string {
    switch (width) {
        case '1/3':
            return __('A third');
        case '1/2':
            return __('Half');
        case '1/1':
            return __('Full width');
    }
}

export function widgetHeightLabel(height: WidgetHeight): string {
    return height === 'm' ? __('Medium') : __('Large');
}
