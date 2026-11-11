import { AccountBalancesWidget } from './account-balances-widget';
import {
    CashflowWidget,
    MonthlySavingsWidget,
    NetWorthWidget,
    TopCategoriesWidget,
    TopLabelsWidget,
} from './system-widgets';
import {
    WIDGET_HEIGHTS,
    WIDGET_WIDTHS,
    type DashboardLayout,
    type SystemWidgetDefinition,
    type WidgetSize,
} from './types';

export const SYSTEM_WIDGET_IDS = [
    'net-worth',
    'account-balances',
    'monthly-savings',
    'top-categories',
    'top-labels',
    'cashflow',
] as const;

export type SystemWidgetId = (typeof SYSTEM_WIDGET_IDS)[number];

const HALF_OR_FULL = ['1/2', '1/1'] as const;

/**
 * The widgets the app ships with. Ids are stable: saved dashboard layouts
 * point at them, so renaming one orphans it on every saved dashboard.
 */
export const SYSTEM_WIDGETS: {
    [Id in SystemWidgetId]: SystemWidgetDefinition<Id>;
} = {
    'net-worth': {
        id: 'net-worth',
        defaultSize: { width: '1/1', height: 'l' },
        allowedWidths: HALF_OR_FULL,
        allowedHeights: WIDGET_HEIGHTS,
        component: NetWorthWidget,
    },
    'account-balances': {
        id: 'account-balances',
        defaultSize: { width: '1/1', height: 'l' },
        allowedWidths: HALF_OR_FULL,
        allowedHeights: WIDGET_HEIGHTS,
        component: AccountBalancesWidget,
    },
    'monthly-savings': {
        id: 'monthly-savings',
        defaultSize: { width: '1/1', height: 'm' },
        allowedWidths: WIDGET_WIDTHS,
        allowedHeights: WIDGET_HEIGHTS,
        component: MonthlySavingsWidget,
    },
    'top-categories': {
        id: 'top-categories',
        defaultSize: { width: '1/1', height: 'l' },
        allowedWidths: HALF_OR_FULL,
        allowedHeights: WIDGET_HEIGHTS,
        component: TopCategoriesWidget,
    },
    'top-labels': {
        id: 'top-labels',
        defaultSize: { width: '1/1', height: 'm' },
        allowedWidths: HALF_OR_FULL,
        allowedHeights: WIDGET_HEIGHTS,
        component: TopLabelsWidget,
    },
    cashflow: {
        id: 'cashflow',
        defaultSize: { width: '1/1', height: 'm' },
        allowedWidths: HALF_OR_FULL,
        allowedHeights: ['m'],
        component: CashflowWidget,
    },
};

export function isSystemWidgetId(id: string): id is SystemWidgetId {
    return Object.hasOwn(SYSTEM_WIDGETS, id);
}

export function isSizeAllowed(
    widget: Pick<SystemWidgetDefinition, 'allowedWidths' | 'allowedHeights'>,
    size: WidgetSize,
): boolean {
    return (
        widget.allowedWidths.includes(size.width) &&
        widget.allowedHeights.includes(size.height)
    );
}

/** The dashboard every user has today: each system widget at its default size. */
export const SYSTEM_DASHBOARD_LAYOUT: DashboardLayout<SystemWidgetId> =
    SYSTEM_WIDGET_IDS.map((id) => ({
        widget: id,
        size: SYSTEM_WIDGETS[id].defaultSize,
    }));

/**
 * Makes a stored layout safe to draw: widgets that no longer exist and repeats
 * are dropped, and a size the widget does not admit falls back to its default.
 */
export function resolveLayout(
    layout: DashboardLayout,
): DashboardLayout<SystemWidgetId> {
    const seen = new Set<SystemWidgetId>();

    return layout.flatMap((item) => {
        if (!isSystemWidgetId(item.widget) || seen.has(item.widget)) {
            return [];
        }
        seen.add(item.widget);
        const definition = SYSTEM_WIDGETS[item.widget];

        return [
            {
                widget: item.widget,
                size: isSizeAllowed(definition, item.size)
                    ? item.size
                    : definition.defaultSize,
            },
        ];
    });
}
