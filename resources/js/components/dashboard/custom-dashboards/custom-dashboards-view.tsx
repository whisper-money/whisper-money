import { DashboardLayoutView } from '@/components/dashboard/widgets/dashboard-layout-view';
import {
    SYSTEM_DASHBOARD_LAYOUT,
    type SystemWidgetId,
} from '@/components/dashboard/widgets/registry';
import { Button } from '@/components/ui/button';
import { subscribe } from '@/routes';
import {
    type DashboardProps,
    type SelectedDashboard,
    type WidgetDefinitionSummary,
} from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { SafeModeNotice } from '@/widgets/runtime/safe-mode-notice';
import { Link } from '@inertiajs/react';
import { Check, LayoutGrid, Pencil } from 'lucide-react';
import { type ReactNode, useMemo, useState } from 'react';
import { AddWidgetMenu } from './add-widget-menu';
import { dashboardActions } from './dashboard-actions';
import { DashboardGrid, EditableDashboardGrid } from './dashboard-grid';
import { DashboardNameDialog } from './dashboard-name-dialog';
import { DashboardPicker } from './dashboard-picker';
import {
    missingSystemWidgets,
    missingUserWidgets,
    type PlacedWidget,
    placedWidgets,
} from './grid-layout';
import { systemDashboardName } from './labels';
import { LockedDashboard } from './locked-dashboard';
import { ManageDashboardsDialog } from './manage-dashboards-dialog';
import { StandardPlanDialog } from './standard-plan-notice';
import { useUserWidgetSlots } from './use-user-widget-slots';

interface Props {
    data: DashboardProps;
    heading: ReactNode;
    /** Drawn between the heading and the widgets, as on the system dashboard. */
    notice: ReactNode;
}

/**
 * The dashboard page with the user's own dashboards: a picker next to the
 * heading, and the selected dashboard drawn on the 12-column grid. The system
 * dashboard keeps its fixed, read-only layout.
 */
export function CustomDashboardsView({ data, heading, notice }: Props) {
    const { auth } = data;
    const selected = data.selectedDashboard;
    const canManage = !auth.isSharedAccount;
    const canEdit = canManage && auth.hasProPlan;

    // Edit mode belongs to one dashboard: opening another one reads it.
    const [editingId, setEditingId] = useState<string | null>(null);
    const [creating, setCreating] = useState(false);
    const [upselling, setUpselling] = useState(false);
    const [managing, setManaging] = useState(false);

    // The list is deferred; until it lands, the one on screen is all we know.
    const dashboards =
        data.dashboards ??
        (selected
            ? [{ id: selected.id, name: selected.name, position: 0 }]
            : []);

    const isEditing = canEdit && selected !== null && editingId === selected.id;
    const placed = useMemo(
        () => placedWidgets(selected?.widgets ?? [], data.userWidgets),
        [selected?.widgets, data.userWidgets],
    );

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-4">
                {heading}
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                    {/* An empty dashboard offers its own Add widget button. */}
                    {isEditing && placed.length > 0 && (
                        <AddWidgetMenu
                            available={missingSystemWidgets(placed)}
                            onAdd={(widget) =>
                                dashboardActions.addWidget(selected.id, widget)
                            }
                            userWidgets={missingUserWidgets(
                                placed,
                                data.widgetDefinitions ?? [],
                            )}
                            onAddUserWidget={(definitionId) =>
                                dashboardActions.addUserWidget(
                                    selected.id,
                                    definitionId,
                                )
                            }
                        />
                    )}
                    {canEdit && selected !== null && (
                        <Button
                            variant={isEditing ? 'default' : 'outline'}
                            size="sm"
                            onClick={() =>
                                setEditingId(isEditing ? null : selected.id)
                            }
                        >
                            {isEditing ? (
                                <Check className="size-4" />
                            ) : (
                                <Pencil className="size-4" />
                            )}
                            {isEditing ? __('Done') : __('Edit')}
                        </Button>
                    )}
                    <DashboardPicker
                        dashboards={dashboards}
                        selectedId={selected?.id ?? null}
                        selectedName={selected?.name ?? systemDashboardName()}
                        defaultId={data.defaultDashboardId}
                        canCreate={canEdit}
                        canManage={canManage}
                        isLoading={data.dashboards === undefined}
                        onCreate={() =>
                            canEdit ? setCreating(true) : setUpselling(true)
                        }
                        onManage={() => setManaging(true)}
                    />
                </div>
            </div>

            {notice}

            {selected === null ? (
                <DashboardLayoutView
                    layout={SYSTEM_DASHBOARD_LAYOUT}
                    data={data}
                />
            ) : isLocked(data, selected) ? (
                <LockedDashboard dashboard={selected} />
            ) : (
                <UserDashboard
                    dashboard={selected}
                    widgets={placed}
                    data={data}
                    isEditing={isEditing}
                    canEdit={canEdit}
                    onStartEditing={() => setEditingId(selected.id)}
                />
            )}

            <DashboardNameDialog open={creating} onOpenChange={setCreating} />
            <StandardPlanDialog open={upselling} onOpenChange={setUpselling} />
            <ManageDashboardsDialog
                open={managing}
                onOpenChange={setManaging}
                dashboards={dashboards}
                defaultId={data.defaultDashboardId}
                canEdit={canEdit}
            />
        </>
    );
}

/**
 * One of the user's dashboards, opened off the paid plan: drawn as
 * placeholders. An empty one has nothing to hide, and says why it cannot be
 * filled.
 */
function isLocked(data: DashboardProps, dashboard: SelectedDashboard): boolean {
    return !data.auth.hasProPlan && dashboard.widgets.length > 0;
}

function UserDashboard({
    dashboard,
    widgets,
    data,
    isEditing,
    canEdit,
    onStartEditing,
}: {
    dashboard: SelectedDashboard;
    widgets: PlacedWidget[];
    data: DashboardProps;
    isEditing: boolean;
    canEdit: boolean;
    onStartEditing: () => void;
}) {
    const { gate, slots } = useUserWidgetSlots({
        dashboard,
        placed: widgets,
        data,
        canEdit,
    });

    if (widgets.length === 0) {
        return (
            <EmptyDashboard
                canEdit={canEdit}
                isSharedAccount={data.auth.isSharedAccount}
                userWidgets={data.widgetDefinitions ?? []}
                onAdd={(widget) => {
                    onStartEditing();
                    dashboardActions.addWidget(dashboard.id, widget);
                }}
                onAddUserWidget={(definitionId) => {
                    onStartEditing();
                    dashboardActions.addUserWidget(dashboard.id, definitionId);
                }}
            />
        );
    }

    return (
        <>
            {gate.mode === 'safe' && (
                <SafeModeNotice reason={gate.reason} onExit={gate.exit} />
            )}
            {isEditing ? (
                <EditableDashboardGrid
                    widgets={widgets}
                    data={data}
                    userWidgets={slots}
                    onReorder={(ids) =>
                        dashboardActions.reorderWidgets(
                            dashboard.id,
                            withUndrawnPlacements(ids, dashboard),
                        )
                    }
                    onResize={(id, size) =>
                        dashboardActions.resizeWidget(dashboard.id, id, size)
                    }
                    onRemove={(id) =>
                        dashboardActions.removeWidget(dashboard.id, id)
                    }
                />
            ) : (
                <DashboardGrid
                    widgets={widgets}
                    data={data}
                    userWidgets={slots}
                />
            )}
        </>
    );
}

/**
 * The new order with the placements the page does not draw (a widget that
 * is gone) kept after it, so every placement gets a position of its own.
 */
function withUndrawnPlacements(
    ids: string[],
    dashboard: SelectedDashboard,
): string[] {
    const drawn = new Set(ids);

    return [
        ...ids,
        ...[...dashboard.widgets]
            .sort((a, b) => a.position - b.position)
            .map(({ id }) => id)
            .filter((id) => !drawn.has(id)),
    ];
}

function EmptyDashboard({
    canEdit,
    isSharedAccount,
    userWidgets,
    onAdd,
    onAddUserWidget,
}: {
    canEdit: boolean;
    isSharedAccount: boolean;
    userWidgets: WidgetDefinitionSummary[];
    onAdd: (widget: SystemWidgetId) => void;
    onAddUserWidget: (definitionId: string) => void;
}) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border-2 border-dashed px-6 py-16 text-center">
            <LayoutGrid className="size-8 text-muted-foreground" />
            <div className="flex flex-col gap-1">
                <p className="font-medium">{__('This dashboard is empty')}</p>
                {canEdit && (
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'Add the widgets you want to see here, then drag them into place.',
                        )}
                    </p>
                )}
                {!canEdit && !isSharedAccount && (
                    <p className="text-sm text-muted-foreground">
                        {__('Custom dashboards are part of the Standard plan.')}{' '}
                        <Link
                            href={subscribe().url}
                            className="font-medium text-foreground underline underline-offset-4"
                        >
                            {__('See plans')}
                        </Link>
                    </p>
                )}
            </div>
            {canEdit && (
                <AddWidgetMenu
                    variant="default"
                    available={missingSystemWidgets([])}
                    onAdd={onAdd}
                    userWidgets={userWidgets}
                    onAddUserWidget={onAddUserWidget}
                />
            )}
        </div>
    );
}
