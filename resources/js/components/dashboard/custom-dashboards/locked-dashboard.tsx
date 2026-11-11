import { trackLockedDashboardViewed } from '@/lib/custom-dashboards-telemetry';
import { cn } from '@/lib/utils';
import { type SelectedDashboard } from '@/types/dashboard';
import { useEffect } from 'react';
import { DASHBOARD_GRID_CLASS_NAME, widgetSizeClassName } from './grid-layout';
import { LockedDashboardNotice } from './standard-plan-notice';

/** The bars of a placeholder's made-up chart, as a share of its height. */
const PLACEHOLDER_BARS = [40, 65, 50, 80, 60, 90];

/**
 * One widget's place, drawn as a card of grey bars at the widget's size. It
 * holds nothing of the widget: no data and no code.
 */
function WidgetPlaceholder() {
    return (
        <div className="flex h-full flex-col gap-4 rounded-xl border bg-card p-6">
            <div className="h-4 w-2/5 rounded bg-muted" />
            <div className="h-7 w-1/3 rounded bg-muted" />
            <div className="flex min-h-0 flex-1 items-end gap-2">
                {PLACEHOLDER_BARS.map((height, index) => (
                    <div
                        key={index}
                        className="flex-1 rounded-t bg-muted"
                        style={{ height: `${height}%` }}
                    />
                ))}
            </div>
        </div>
    );
}

/**
 * One of the user's dashboards, opened without the paid plan: its layout
 * kept, every widget a blurred placeholder at its size, and the notice to
 * upgrade over it. The page has neither the widgets' code nor their data
 * (the server sends none), so nothing real sits under the blur.
 */
export function LockedDashboard({
    dashboard,
}: {
    dashboard: SelectedDashboard;
}) {
    const widgets = [...dashboard.widgets].sort(
        (a, b) => a.position - b.position,
    );

    useEffect(() => {
        trackLockedDashboardViewed(dashboard.widgets.length);
    }, [dashboard.id, dashboard.widgets.length]);

    return (
        <div className="relative" data-testid="locked-dashboard">
            <div
                aria-hidden
                inert
                className={cn(
                    DASHBOARD_GRID_CLASS_NAME,
                    'pointer-events-none blur-[3px] select-none',
                )}
            >
                {widgets.map((widget) => (
                    <div
                        key={widget.id}
                        data-widget-placeholder
                        className={widgetSizeClassName(widget)}
                    >
                        <WidgetPlaceholder />
                    </div>
                ))}
            </div>
            {/* Sticky, so the way out stays in view on a long dashboard. */}
            <div className="absolute inset-0 px-4 pt-16">
                <div className="sticky top-24 flex justify-center">
                    <LockedDashboardNotice />
                </div>
            </div>
        </div>
    );
}
