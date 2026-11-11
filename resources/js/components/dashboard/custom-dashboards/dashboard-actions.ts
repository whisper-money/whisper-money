import {
    destroy as destroyDashboardAction,
    reorder as reorderDashboardsAction,
} from '@/actions/App/Http/Controllers/Dashboards/DashboardManagementController';
import {
    destroy as destroyWidgetAction,
    reorder as reorderWidgetsAction,
    store as storeWidgetAction,
    update as updateWidgetAction,
} from '@/actions/App/Http/Controllers/Dashboards/DashboardWidgetController';
import updateDefaultDashboard from '@/actions/App/Http/Controllers/Dashboards/DefaultDashboardController';
import { type SystemWidgetId } from '@/components/dashboard/widgets/registry';
import { type WidgetSize } from '@/components/dashboard/widgets/types';
import { dashboard as dashboardRoute } from '@/routes';
import {
    type DashboardProps,
    type DashboardSummary,
    type DashboardWidgetData,
    type SelectedDashboard,
} from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { type Errors, type VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';

type Optimistic = (props: DashboardProps) => Partial<DashboardProps>;

/**
 * The `?dashboard=` value that opens the system dashboard; the server side is
 * `DashboardController::SYSTEM_DASHBOARD`.
 */
export const SYSTEM_DASHBOARD_QUERY = 'system';

function sayItFailed(): false {
    toast.error(__('Something went wrong.'));

    // Handled: no Inertia error page over the dashboard.
    return false;
}

/**
 * A change that stays on the page: only the props it touches come back, and
 * an optimistic one is drawn at once and rolled back if the server refuses it.
 */
function stay(
    only: (keyof DashboardProps & string)[],
    optimistic?: Optimistic,
): VisitOptions {
    return {
        preserveScroll: true,
        preserveState: true,
        only,
        // Inertia types the callback against the shared props only.
        optimistic: optimistic as VisitOptions['optimistic'],
        onError: (errors: Errors) => {
            toast.error(
                Object.values(errors)[0] ?? __('Something went wrong.'),
            );
        },
        onHttpException: sayItFailed,
    };
}

function withWidgets(
    dashboard: SelectedDashboard | null,
    widgets: (current: DashboardWidgetData[]) => DashboardWidgetData[],
): Partial<DashboardProps> {
    if (dashboard === null) {
        return {};
    }

    return {
        selectedDashboard: {
            ...dashboard,
            widgets: widgets(dashboard.widgets),
        },
    };
}

function inOrder<T extends { id: string; position: number }>(
    items: T[],
    ids: string[],
): T[] {
    const position = new Map(ids.map((id, index) => [id, index]));

    return items
        .map((item) => ({
            ...item,
            position: position.get(item.id) ?? item.position,
        }))
        .sort((a, b) => a.position - b.position);
}

/** The writes the dashboards UI makes, on the dashboard page itself. */
export const dashboardActions = {
    /** Opens a user dashboard, or the system one for null. */
    open(dashboardId: string | null): void {
        router.visit(
            dashboardRoute.url({
                query: { dashboard: dashboardId ?? SYSTEM_DASHBOARD_QUERY },
            }),
        );
    },

    reorderDashboards(ids: string[]): void {
        router.patch(
            reorderDashboardsAction.url(),
            { ids },
            stay(['dashboards'], (props) => ({
                dashboards: inOrder<DashboardSummary>(
                    props.dashboards ?? [],
                    ids,
                ),
            })),
        );
    },

    setDefault(dashboardId: string | null): void {
        router.put(
            updateDefaultDashboard.url(),
            { dashboard_id: dashboardId },
            stay(['defaultDashboardId'], () => ({
                defaultDashboardId: dashboardId,
            })),
        );
    },

    /** The server sends the user back to their default dashboard. */
    destroyDashboard(dashboardId: string, onFinish: () => void): void {
        router.delete(destroyDashboardAction.url(dashboardId), {
            onFinish,
            onHttpException: sayItFailed,
        });
    },

    addWidget(dashboardId: string, widget: SystemWidgetId): void {
        router.post(
            storeWidgetAction.url(dashboardId),
            { system_widget: widget },
            stay(['selectedDashboard']),
        );
    },

    removeWidget(dashboardId: string, widgetId: string): void {
        router.delete(
            destroyWidgetAction.url([dashboardId, widgetId]),
            stay(['selectedDashboard'], (props) =>
                withWidgets(props.selectedDashboard, (widgets) =>
                    widgets.filter(({ id }) => id !== widgetId),
                ),
            ),
        );
    },

    resizeWidget(
        dashboardId: string,
        widgetId: string,
        size: WidgetSize,
    ): void {
        router.patch(
            updateWidgetAction.url([dashboardId, widgetId]),
            { ...size },
            stay(['selectedDashboard'], (props) =>
                withWidgets(props.selectedDashboard, (widgets) =>
                    widgets.map((widget) =>
                        widget.id === widgetId
                            ? { ...widget, ...size }
                            : widget,
                    ),
                ),
            ),
        );
    },

    reorderWidgets(dashboardId: string, ids: string[]): void {
        router.patch(
            reorderWidgetsAction.url(dashboardId),
            { ids },
            stay(['selectedDashboard'], (props) =>
                withWidgets(props.selectedDashboard, (widgets) =>
                    inOrder(widgets, ids),
                ),
            ),
        );
    },
};
