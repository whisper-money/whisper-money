import { type DashboardProps } from '@/types/dashboard';
import { Fragment, type ReactNode } from 'react';
import { SYSTEM_WIDGETS, type SystemWidgetId } from './registry';
import { type DashboardLayout } from './types';

interface Props {
    layout: DashboardLayout<SystemWidgetId>;
    data: DashboardProps;
    /** Drawn in a widget's place; null leaves the widget out. */
    replacements?: Partial<Record<SystemWidgetId, ReactNode>>;
}

/** Draws a dashboard layout, one system widget after another. */
export function DashboardLayoutView({ layout, data, replacements }: Props) {
    return (
        <div className="flex flex-col gap-6">
            {layout.map(({ widget, size }) => {
                if (replacements && Object.hasOwn(replacements, widget)) {
                    return (
                        <Fragment key={widget}>{replacements[widget]}</Fragment>
                    );
                }

                const Widget = SYSTEM_WIDGETS[widget].component;

                return <Widget key={widget} data={data} size={size} />;
            })}
        </div>
    );
}
