import {
    isSizeAllowed,
    isSystemWidgetId,
    SYSTEM_WIDGET_IDS,
    SYSTEM_WIDGETS,
    type SystemWidgetId,
} from '@/components/dashboard/widgets/registry';
import {
    type WidgetHeight,
    type WidgetSize,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import { type DashboardWidgetData } from '@/types/dashboard';

/**
 * Twelve columns, so a third (4) and a half (6) share one grid, over rows of a
 * fixed height so a large widget lines up with two medium ones stacked. Dense
 * packing fills the holes that mixing widths leaves.
 *
 * The grid is a size container: widths answer to the room the grid has, not
 * to the viewport, which the sidebar shares on a tablet.
 */
export const DASHBOARD_GRID_CLASS_NAME =
    '@container grid grid-cols-12 grid-flow-dense auto-rows-[16rem] gap-6';

/**
 * Full width on a phone-sized grid; on a tablet-sized one a third is too
 * narrow, so it takes half.
 */
const WIDTH_CLASS_NAMES: Record<WidgetWidth, string> = {
    '1/3': 'col-span-12 @2xl:col-span-6 @4xl:col-span-4',
    '1/2': 'col-span-12 @2xl:col-span-6',
    '1/1': 'col-span-12',
};

const HEIGHT_CLASS_NAMES: Record<WidgetHeight, string> = {
    m: 'row-span-1',
    l: 'row-span-2',
};

export function widgetSizeClassName(size: WidgetSize): string {
    return `${WIDTH_CLASS_NAMES[size.width]} ${HEIGHT_CLASS_NAMES[size.height]}`;
}

/** A system widget placed on a user dashboard, ready to draw. */
export interface PlacedSystemWidget {
    /** The placement's id, which the widget routes take. */
    id: string;
    widget: SystemWidgetId;
    size: WidgetSize;
}

/**
 * The system widgets of a user dashboard, in order. Placements the registry
 * cannot draw (a widget that was removed, or a user-written one, which this
 * page does not render yet) are left out, and a size the widget no longer
 * admits falls back to its default.
 */
export function placedSystemWidgets(
    widgets: DashboardWidgetData[],
): PlacedSystemWidget[] {
    return [...widgets]
        .sort((a, b) => a.position - b.position)
        .flatMap((placement) => {
            const id = placement.system_widget;
            if (id === null || !isSystemWidgetId(id)) {
                return [];
            }

            const size = { width: placement.width, height: placement.height };

            return [
                {
                    id: placement.id,
                    widget: id,
                    size: isSizeAllowed(SYSTEM_WIDGETS[id], size)
                        ? size
                        : SYSTEM_WIDGETS[id].defaultSize,
                },
            ];
        });
}

/** The system widgets not yet on the dashboard, which edit mode can add. */
export function missingSystemWidgets(
    placed: PlacedSystemWidget[],
): SystemWidgetId[] {
    const onDashboard = new Set(placed.map(({ widget }) => widget));

    return SYSTEM_WIDGET_IDS.filter((id) => !onDashboard.has(id));
}
