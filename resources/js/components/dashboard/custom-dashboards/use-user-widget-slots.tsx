import { type DashboardProps, type SelectedDashboard } from '@/types/dashboard';
import {
    useUserWidgetsGate,
    type UserWidgetsGate,
} from '@/widgets/runtime/use-user-widgets-gate';
import {
    lazy,
    Suspense,
    useCallback,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { dashboardActions } from './dashboard-actions';
import { type UserWidgetSlots } from './dashboard-grid';
import { userWidgetSizeRules, type PlacedWidget } from './grid-layout';
import { useUserWidgetData } from './use-user-widget-data';
import { useWidgetErrorReporter } from './use-widget-error-reporter';
import { UserWidgetCell } from './user-widget-cell';
import { type WidgetTransactionRequest } from './widget-transaction-editor';

// Its own chunk: the dialogs load the first time a widget's row is tapped.
const WidgetTransactionEditor = lazy(
    () => import('./widget-transaction-editor'),
);

/**
 * Everything the grid needs to draw the user widgets of the dashboard on
 * screen: whether they may run (safe mode), their data in one request, their
 * runtime errors stored with them, a cell for each, and the edit dialog a
 * widget's transaction opens.
 */
export function useUserWidgetSlots({
    dashboard,
    placed,
    data,
    canEdit,
    isEditing,
}: {
    dashboard: SelectedDashboard;
    placed: PlacedWidget[];
    data: DashboardProps;
    canEdit: boolean;
    isEditing: boolean;
}): {
    gate: UserWidgetsGate;
    slots: UserWidgetSlots;
    transactionEditor: ReactNode;
} {
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
    const [refreshToken, setRefreshToken] = useState(0);
    const widgetData = useUserWidgetData(
        dashboard.id,
        definitions,
        gate.mode === 'run',
        { refreshToken },
    );
    const [transactionRequest, setTransactionRequest] =
        useState<WidgetTransactionRequest | null>(null);
    // While the dashboard is arranged a tap on a row is not an edit.
    const requestTransaction = useCallback(
        (transactionId: string) =>
            setTransactionRequest((previous) => ({
                transactionId,
                sequence: (previous?.sequence ?? 0) + 1,
            })),
        [],
    );
    const openTransaction = isEditing ? undefined : requestTransaction;
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
                isEditing={isEditing}
                onOpenTransaction={openTransaction}
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

    const transactionEditor = transactionRequest && (
        <Suspense fallback={null}>
            <WidgetTransactionEditor
                request={transactionRequest}
                onChanged={() => setRefreshToken((count) => count + 1)}
            />
        </Suspense>
    );

    return { gate, slots, transactionEditor };
}
