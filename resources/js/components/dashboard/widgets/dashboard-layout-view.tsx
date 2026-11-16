import { type DashboardProps } from '@/types/dashboard';
import { SYSTEM_WIDGETS, type SystemWidgetId } from './registry';
import { type DashboardLayout } from './types';

interface Props {
    layout: DashboardLayout<SystemWidgetId>;
    data: DashboardProps;
}

/** Draws a dashboard layout, one system widget after another. */
export function DashboardLayoutView({ layout, data }: Props) {
    return (
        <div className="flex flex-col gap-6">
            {layout.map(({ widget, size }) => {
                const Widget = SYSTEM_WIDGETS[widget].component;

                return <Widget key={widget} data={data} size={size} />;
            })}
        </div>
    );
}
