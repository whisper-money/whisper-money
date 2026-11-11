import { type DashboardProps } from '@/types/dashboard';
import { type ComponentType } from 'react';

/** Fraction of the dashboard row a widget spans. */
export type WidgetWidth = '1/3' | '1/2' | '1/1';

/** Widget height: `l` is exactly twice `m`. */
export type WidgetHeight = 'm' | 'l';

export interface WidgetSize {
    width: WidgetWidth;
    height: WidgetHeight;
}

export const WIDGET_WIDTHS: readonly WidgetWidth[] = ['1/3', '1/2', '1/1'];

export const WIDGET_HEIGHTS: readonly WidgetHeight[] = ['m', 'l'];

export interface SystemWidgetProps {
    /** The dashboard page props the widget reads its data from. */
    data: DashboardProps;
    size: WidgetSize;
}

export interface SystemWidgetDefinition<Id extends string = string> {
    /** Stable id: saved dashboard layouts refer to the widget by it. */
    id: Id;
    defaultSize: WidgetSize;
    allowedWidths: readonly WidgetWidth[];
    allowedHeights: readonly WidgetHeight[];
    component: ComponentType<SystemWidgetProps>;
}

/** One widget placed on a dashboard. */
export interface DashboardLayoutItem<Id extends string = string> {
    widget: Id;
    size: WidgetSize;
}

/** The widgets of a dashboard, in the order they are drawn. */
export type DashboardLayout<Id extends string = string> =
    DashboardLayoutItem<Id>[];
