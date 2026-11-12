import {
    SYSTEM_WIDGETS,
    type SystemWidgetId,
} from '@/components/dashboard/widgets/registry';
import { type WidgetSize } from '@/components/dashboard/widgets/types';
import { SortableGrid } from '@/components/sortable-grid';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type DashboardProps } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { type Announcements, type UniqueIdentifier } from '@dnd-kit/core';
import { X } from 'lucide-react';
import { type ReactNode } from 'react';
import {
    DASHBOARD_GRID_CLASS_NAME,
    type PlacedUserWidget,
    type PlacedWidget,
    widgetSizeClassName,
    type WidgetSizeRules,
} from './grid-layout';
import { systemWidgetLabel } from './labels';
import { WidgetSizeMenu } from './widget-size-menu';

/**
 * One system widget, filling its cell and scrolling inside it when it has more
 * to show than the cell's fixed height. A widget with nothing to show leaves
 * the element empty, which `empty:` variants pick up.
 */
function WidgetBody({
    widget,
    size,
    data,
    className,
    ...attributes
}: {
    widget: SystemWidgetId;
    size: WidgetSize;
    data: DashboardProps;
    className: string;
    'data-widget'?: string;
}) {
    const Widget = SYSTEM_WIDGETS[widget].component;

    return (
        <div
            {...attributes}
            className={cn(
                'flex h-full min-h-0 flex-col gap-4 overflow-y-auto [&>[data-slot=card]]:min-h-full',
                className,
            )}
        >
            <Widget data={data} size={size} />
        </div>
    );
}

/** How the grid draws the widgets the user's agent wrote. */
export interface UserWidgetSlots {
    render: (item: PlacedUserWidget) => ReactNode;
    /** The widget's name, for edit mode's bar. */
    label: (item: PlacedUserWidget) => string;
    /** The sizes it admits; null until its manifest has loaded. */
    sizes: (item: PlacedUserWidget) => WidgetSizeRules | null;
}

interface Props {
    widgets: PlacedWidget[];
    data: DashboardProps;
    /** Without it, user widgets are left out. */
    userWidgets?: UserWidgetSlots;
}

/** The attribute browser tests and styles find a widget's cell by. */
function widgetAttribute(item: PlacedWidget): string {
    return item.kind === 'system' ? item.widget : `user:${item.definitionId}`;
}

function drawable(
    widgets: PlacedWidget[],
    userWidgets: UserWidgetSlots | undefined,
): PlacedWidget[] {
    return userWidgets
        ? widgets
        : widgets.filter((item) => item.kind === 'system');
}

/**
 * A user dashboard as it reads. A system widget with nothing to show
 * (cashflow switched off, no monthly goal, nothing labelled) renders nothing,
 * and its cell, left empty, is hidden: the grid never keeps a hole for it.
 */
export function DashboardGrid({ widgets, data, userWidgets }: Props) {
    return (
        <div className={DASHBOARD_GRID_CLASS_NAME} data-testid="dashboard-grid">
            {drawable(widgets, userWidgets).map((item) =>
                item.kind === 'system' ? (
                    <WidgetBody
                        key={item.id}
                        data-widget={item.widget}
                        widget={item.widget}
                        size={item.size}
                        data={data}
                        className={cn(
                            widgetSizeClassName(item.size),
                            'empty:hidden',
                        )}
                    />
                ) : (
                    <div
                        key={item.id}
                        data-widget={widgetAttribute(item)}
                        className={widgetSizeClassName(item.size)}
                    >
                        {userWidgets?.render(item)}
                    </div>
                ),
            )}
        </div>
    );
}

interface EditableProps extends Props {
    onReorder: (ids: string[]) => void;
    onResize: (id: string, size: WidgetSize) => void;
    onRemove: (id: string) => void;
}

/**
 * The same grid in edit mode: every widget carries a bar to drag it (by
 * pointer or keyboard), size it and remove it. Widgets with nothing to show
 * keep their cell here, with a note, so they can still be moved or removed.
 */
export function EditableDashboardGrid({
    widgets,
    data,
    userWidgets,
    onReorder,
    onResize,
    onRemove,
}: EditableProps) {
    const items = drawable(widgets, userWidgets);

    return (
        <SortableGrid
            className={DASHBOARD_GRID_CLASS_NAME}
            items={items}
            getId={(item) => item.id}
            getItemClassName={(item) => widgetSizeClassName(item.size)}
            translateOnly
            onReorder={onReorder}
            draggingClassName="opacity-80 shadow-lg"
            accessibility={widgetAccessibility(items)}
            renderItem={(item, dragHandle) => {
                const { id, size } = item;
                const label =
                    item.kind === 'system'
                        ? systemWidgetLabel(item.widget)
                        : (userWidgets?.label(item) ?? '');
                const sizes =
                    item.kind === 'system'
                        ? SYSTEM_WIDGETS[item.widget]
                        : (userWidgets?.sizes(item) ?? null);

                return (
                    <div
                        data-widget={widgetAttribute(item)}
                        className="flex h-full flex-col gap-2 rounded-xl border-2 border-dashed border-primary/30 p-2"
                    >
                        <div className="flex items-center gap-2">
                            {dragHandle}
                            <span className="min-w-0 flex-1 truncate text-sm font-medium">
                                {label}
                            </span>
                            {sizes !== null && (
                                <WidgetSizeMenu
                                    definition={sizes}
                                    size={size}
                                    label={label}
                                    onChange={(next) => onResize(id, next)}
                                />
                            )}
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                aria-label={__('Remove :widget', {
                                    widget: label,
                                })}
                                onClick={() => onRemove(id)}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>
                        {item.kind === 'system' ? (
                            <>
                                <WidgetBody
                                    widget={item.widget}
                                    size={size}
                                    data={data}
                                    className="peer empty:hidden"
                                />
                                <p className="hidden flex-1 items-center justify-center text-center text-sm text-muted-foreground peer-empty:flex">
                                    {__('Nothing to show here yet.')}
                                </p>
                            </>
                        ) : (
                            <div className="min-h-0 flex-1">
                                {userWidgets?.render(item)}
                            </div>
                        )}
                    </div>
                );
            }}
        />
    );
}

function widgetAccessibility(widgets: PlacedWidget[]): {
    announcements: Announcements;
    screenReaderInstructions: { draggable: string };
} {
    const positionOf = (id: UniqueIdentifier) => ({
        position: widgets.findIndex((item) => item.id === id) + 1,
        total: widgets.length,
    });

    return {
        screenReaderInstructions: {
            draggable: __(
                'To pick up a widget, press Space or Enter. Use the arrow keys to move it, Space or Enter to drop it, and Escape to cancel.',
            ),
        },
        announcements: {
            onDragStart: ({ active }) =>
                __(
                    'Picked up widget :position of :total',
                    positionOf(active.id),
                ),
            onDragOver: ({ over }) =>
                over
                    ? __(
                          'Widget moved to position :position of :total',
                          positionOf(over.id),
                      )
                    : undefined,
            onDragEnd: ({ over }) =>
                over
                    ? __(
                          'Widget dropped at position :position of :total',
                          positionOf(over.id),
                      )
                    : undefined,
            onDragCancel: () => __('Moving the widget was cancelled.'),
        },
    };
}
