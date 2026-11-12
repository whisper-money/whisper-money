import { type DashboardProps, type SelectedDashboard } from '@/types/dashboard';
import {
    useUserWidgetsGate,
    type UserWidgetsGate,
} from '@/widgets/runtime/use-user-widgets-gate';
import { useMemo } from 'react';
import { dashboardActions } from './dashboard-actions';
import { type UserWidgetSlots } from './dashboard-grid';
import { userWidgetSizeRules, type PlacedWidget } from './grid-layout';
import { useUserWidgetData } from './use-user-widget-data';
import { useWidgetErrorReporter } from './use-widget-error-reporter';
import { UserWidgetCell } from './user-widget-cell';

/**
 * Everything the grid needs to draw the user widgets of the dashboard on
 * screen: whether they may run (safe mode), their data in one request, their
 * runtime errors stored with them, and a cell for each.
 */
export function useUserWidgetSlots({
    dashboard,
    placed,
    data,
    canEdit,
}: {
    dashboard: SelectedDashboard;
    placed: PlacedWidget[];
    data: DashboardProps;
    canEdit: boolean;
}): { gate: UserWidgetsGate; slots: UserWidgetSlots } {
    const placementIds = useMemo(
        () => placed.flatMap((item) => (item.kind === 'user' ? [item.id] : [])),
        [placed],
    );
    const gate = useUserWidgetsGate(placementIds);
    const definitions = useMemo(
        () =>
            data.userWidgets === undefined
                ? undefined
                : Object.values(data.userWidgets),
        [data.userWidgets],
    );
    const widgetData = useUserWidgetData(
        dashboard.id,
        definitions,
        gate.mode === 'run',
    );
    const { errors, report } = useWidgetErrorReporter(
        !data.auth.isSharedAccount,
    );

    const slots: UserWidgetSlots = {
        label: (item) => data.userWidgets?.[item.definitionId]?.name ?? '',
        sizes: (item) => {
            const definition = data.userWidgets?.[item.definitionId];

            return definition
                ? userWidgetSizeRules(definition.manifest.size)
                : null;
        },
        render: (item) => (
            <UserWidgetCell
                placement={item}
                definition={data.userWidgets?.[item.definitionId]}
                gate={gate}
                data={widgetData}
                caughtError={errors[item.definitionId]}
                onError={report}
                canRevert={canEdit}
                onRemove={
                    canEdit
                        ? () =>
                              dashboardActions.removeWidget(
                                  dashboard.id,
                                  item.id,
                              )
                        : undefined
                }
            />
        ),
    };

    return { gate, slots };
}
