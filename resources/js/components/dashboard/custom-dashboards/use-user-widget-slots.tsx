import { TransactionListSkeleton } from '@/components/transactions/transaction-list-skeleton';
import { type DashboardProps, type SelectedDashboard } from '@/types/dashboard';
import {
    useUserWidgetsGate,
    type UserWidgetsGate,
} from '@/widgets/runtime/use-user-widgets-gate';
import { type RenderWidgetTransactionTable } from '@/widgets/sdk/transaction-table-host';
import { lazy, Suspense, useCallback, useMemo, useState } from 'react';
import { dashboardActions } from './dashboard-actions';
import { type UserWidgetSlots } from './dashboard-grid';
import { userWidgetSizeRules, type PlacedWidget } from './grid-layout';
import { useUserWidgetData } from './use-user-widget-data';
import { useWidgetErrorReporter } from './use-widget-error-reporter';
import { UserWidgetCell } from './user-widget-cell';

// Its own chunk: the table, its dialogs and bulk actions load with the first
// widget that lists transactions.
const WidgetTransactionTable = lazy(() => import('./widget-transaction-table'));

/**
 * Everything the grid needs to draw the user widgets of the dashboard on
 * screen: whether they may run (safe mode), their data in one request, their
 * runtime errors stored with them, a cell for each, and the transactions
 * table a widget's `<TransactionList>` becomes.
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
    const refresh = useCallback(
        () => setRefreshToken((count) => count + 1),
        [],
    );
    // One bulk actions bar on the page: ticking rows in one widget unticks
    // those of another.
    const [selectionOwner, setSelectionOwner] = useState<string | null>(null);
    const handleSelectionChange = useCallback(
        (widgetId: string, hasSelection: boolean) =>
            setSelectionOwner((owner) =>
                hasSelection ? widgetId : owner === widgetId ? null : owner,
            ),
        [],
    );
    // While the dashboard is arranged a widget's rows are only read.
    const transactionTableFor = (
        widgetId: string,
    ): RenderWidgetTransactionTable | undefined =>
        isEditing
            ? undefined
            : (props) => (
                  <Suspense
                      fallback={<TransactionListSkeleton framedOnPhone />}
                  >
                      <WidgetTransactionTable
                          {...props}
                          widgetId={widgetId}
                          selectionOwner={selectionOwner}
                          onSelectionChange={handleSelectionChange}
                          onChanged={refresh}
                      />
                  </Suspense>
              );
    const { errors, report } = useWidgetErrorReporter(!data.auth.isDemoAccount);

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
                renderTransactionTable={transactionTableFor(item.id)}
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
