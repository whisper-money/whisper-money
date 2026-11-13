import {
    isSizeAllowed,
    isSystemWidgetId,
    SYSTEM_WIDGET_IDS,
    SYSTEM_WIDGETS,
    type SystemWidgetId,
} from '@/components/dashboard/widgets/registry';
import {
    type SystemWidgetDefinition,
    type WidgetHeight,
    type WidgetSize,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import {
    type DashboardWidgetData,
    type UserWidgetDefinition,
    type UserWidgetManifestSize,
    type WidgetDefinitionSummary,
} from '@/types/dashboard';

/**
 * Twelve columns, so a third (4) and a half (6) share one grid. Dense packing
 * fills the holes that mixing widths leaves.
 *
 * The grid is a size container: widths answer to the room the grid has, not
 * to the viewport, which the sidebar shares on a tablet.
 *
 * Rows are at least 16rem and grow with their content. That growth only shows
 * on a phone-sized grid, where each widget has its row to itself: from `@2xl`
 * every cell is size-contained (see HEIGHT_CLASS_NAMES), so its content no
 * longer pushes the row and every row stays 16rem, a large widget lining up
 * with two medium ones stacked. A container query cannot read the grid's own
 * width, which is why the cells, not the grid, switch.
 */
export const DASHBOARD_GRID_CLASS_NAME =
    '@container grid grid-cols-12 grid-flow-dense auto-rows-[minmax(16rem,auto)] gap-6';

/**
 * Full width on a phone-sized grid; on a tablet-sized one a third is too
 * narrow, so it takes half.
 */
const WIDTH_CLASS_NAMES: Record<WidgetWidth, string> = {
    '1/3': 'col-span-12 @2xl:col-span-6 @4xl:col-span-4',
    '1/2': 'col-span-12 @2xl:col-span-6',
    '1/1': 'col-span-12',
};

/**
 * On a phone-sized grid a cell is one row that grows with its widget, never
 * shorter than its height on a wider grid (a chart has no height of its own to
 * grow from): `m` is the row's 16rem, `l` two rows and the gap between them.
 * From `@2xl` the cell spans fixed rows and its content scrolls inside it.
 */
const HEIGHT_CLASS_NAMES: Record<WidgetHeight, string> = {
    m: '@2xl:row-span-1 @2xl:contain-size',
    l: 'min-h-[33.5rem] @2xl:min-h-0 @2xl:row-span-2 @2xl:contain-size',
};

export function widgetSizeClassName(size: WidgetSize): string {
    return `${WIDTH_CLASS_NAMES[size.width]} ${HEIGHT_CLASS_NAMES[size.height]}`;
}

/** A system widget placed on a user dashboard, ready to draw. */
export interface PlacedSystemWidget {
    kind: 'system';
    /** The placement's id, which the widget routes take. */
    id: string;
    widget: SystemWidgetId;
    size: WidgetSize;
}

/** A widget the user's agent wrote, placed on a user dashboard. */
export interface PlacedUserWidget {
    kind: 'user';
    /** The placement's id, which the widget routes take. */
    id: string;
    definitionId: string;
    size: WidgetSize;
}

export type PlacedWidget = PlacedSystemWidget | PlacedUserWidget;

/** The sizes a widget admits and the one it takes when placed. */
export type WidgetSizeRules = Pick<
    SystemWidgetDefinition,
    'defaultSize' | 'allowedWidths' | 'allowedHeights'
>;

/** A user widget's manifest size, read as the rules the size menu offers. */
export function userWidgetSizeRules(
    size: UserWidgetManifestSize,
): WidgetSizeRules {
    return {
        defaultSize: { width: size.width, height: size.height },
        allowedWidths: size.allowed_widths ?? [size.width],
        allowedHeights: size.allowed_heights ?? [size.height],
    };
}

function fitted(size: WidgetSize, rules: WidgetSizeRules | null): WidgetSize {
    if (rules === null || isSizeAllowed(rules, size)) {
        return size;
    }

    return rules.defaultSize;
}

/**
 * The widgets of a user dashboard, in order. Placements the page cannot draw
 * (a system widget that was removed, a user widget that is gone) are left
 * out, and a size the widget no longer admits falls back to its default.
 * Until the user widgets have loaded, theirs keep the size they were saved at.
 */
export function placedWidgets(
    widgets: DashboardWidgetData[],
    userWidgets?: Record<string, UserWidgetDefinition>,
): PlacedWidget[] {
    return [...widgets]
        .sort((a, b) => a.position - b.position)
        .flatMap((placement): PlacedWidget[] => {
            const size = { width: placement.width, height: placement.height };
            const id = placement.system_widget;
            const definitionId = placement.widget_definition_id;

            if (id !== null && isSystemWidgetId(id)) {
                return [
                    {
                        kind: 'system',
                        id: placement.id,
                        widget: id,
                        size: fitted(size, SYSTEM_WIDGETS[id]),
                    },
                ];
            }

            if (definitionId === null) {
                return [];
            }

            if (userWidgets === undefined) {
                return [{ kind: 'user', id: placement.id, definitionId, size }];
            }

            const definition = userWidgets[definitionId];

            return definition === undefined
                ? []
                : [
                      {
                          kind: 'user',
                          id: placement.id,
                          definitionId,
                          size: fitted(
                              size,
                              userWidgetSizeRules(definition.manifest.size),
                          ),
                      },
                  ];
        });
}

/** The system widgets not yet on the dashboard, which edit mode can add. */
export function missingSystemWidgets(placed: PlacedWidget[]): SystemWidgetId[] {
    const onDashboard = new Set(
        placed.flatMap((item) => (item.kind === 'system' ? [item.widget] : [])),
    );

    return SYSTEM_WIDGET_IDS.filter((id) => !onDashboard.has(id));
}

/** The user's own widgets not yet on the dashboard, which edit mode can add. */
export function missingUserWidgets(
    placed: PlacedWidget[],
    definitions: WidgetDefinitionSummary[],
): WidgetDefinitionSummary[] {
    const onDashboard = new Set(
        placed.flatMap((item) =>
            item.kind === 'user' ? [item.definitionId] : [],
        ),
    );

    return definitions.filter(({ id }) => !onDashboard.has(id));
}
