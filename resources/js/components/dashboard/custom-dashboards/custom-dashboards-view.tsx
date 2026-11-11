import { DashboardLayoutView } from '@/components/dashboard/widgets/dashboard-layout-view';
import { SYSTEM_DASHBOARD_LAYOUT } from '@/components/dashboard/widgets/registry';
import { Button } from '@/components/ui/button';
import { type DashboardProps, type SelectedDashboard } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { Check, LayoutGrid, Pencil } from 'lucide-react';
import { type ReactNode, useMemo, useState } from 'react';
import { AddWidgetMenu } from './add-widget-menu';
import { dashboardActions } from './dashboard-actions';
import { DashboardGrid, EditableDashboardGrid } from './dashboard-grid';
import { DashboardNameDialog } from './dashboard-name-dialog';
import { DashboardPicker, systemDashboardName } from './dashboard-picker';
import { missingSystemWidgets, placedSystemWidgets } from './grid-layout';
import { ManageDashboardsDialog } from './manage-dashboards-dialog';

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

    const [editing, setEditing] = useState(false);
    const [creating, setCreating] = useState(false);
    const [managing, setManaging] = useState(false);

    // The list is deferred; until it lands, the one on screen is all we know.
    const dashboards =
        data.dashboards ??
        (selected
            ? [{ id: selected.id, name: selected.name, position: 0 }]
            : []);

    const isEditing = editing && canEdit && selected !== null;

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-4">
                {heading}
                <div className="flex min-w-0 flex-wrap items-center gap-2">
                    {isEditing && (
                        <AddWidgetMenu
                            available={missingSystemWidgets(
                                placedSystemWidgets(selected.widgets),
                            )}
                            onAdd={(widget) =>
                                dashboardActions.addWidget(selected.id, widget)
                            }
                        />
                    )}
                    {canEdit && selected !== null && (
                        <Button
                            variant={isEditing ? 'default' : 'outline'}
                            size="sm"
                            onClick={() => setEditing(!isEditing)}
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
                        onCreate={() => setCreating(true)}
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
            ) : (
                <UserDashboard
                    // A fresh edit mode for each dashboard opened.
                    key={selected.id}
                    dashboard={selected}
                    data={data}
                    isEditing={isEditing}
                    canEdit={canEdit}
                    onStartEditing={() => setEditing(true)}
                />
            )}

            <DashboardNameDialog open={creating} onOpenChange={setCreating} />
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

function UserDashboard({
    dashboard,
    data,
    isEditing,
    canEdit,
    onStartEditing,
}: {
    dashboard: SelectedDashboard;
    data: DashboardProps;
    isEditing: boolean;
    canEdit: boolean;
    onStartEditing: () => void;
}) {
    const widgets = useMemo(
        () => placedSystemWidgets(dashboard.widgets),
        [dashboard.widgets],
    );

    if (widgets.length === 0) {
        return (
            <EmptyDashboard
                canEdit={canEdit}
                onAdd={(widget) => {
                    onStartEditing();
                    dashboardActions.addWidget(dashboard.id, widget);
                }}
            />
        );
    }

    if (!isEditing) {
        return <DashboardGrid widgets={widgets} data={data} />;
    }

    return (
        <EditableDashboardGrid
            widgets={widgets}
            data={data}
            onReorder={(ids) =>
                dashboardActions.reorderWidgets(dashboard.id, ids)
            }
            onResize={(id, size) =>
                dashboardActions.resizeWidget(dashboard.id, id, size)
            }
            onRemove={(id) => dashboardActions.removeWidget(dashboard.id, id)}
        />
    );
}

function EmptyDashboard({
    canEdit,
    onAdd,
}: {
    canEdit: boolean;
    onAdd: Parameters<typeof AddWidgetMenu>[0]['onAdd'];
}) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border-2 border-dashed px-6 py-16 text-center">
            <LayoutGrid className="size-8 text-muted-foreground" />
            <div className="flex flex-col gap-1">
                <p className="font-medium">{__('This dashboard is empty')}</p>
                <p className="text-sm text-muted-foreground">
                    {canEdit
                        ? __(
                              'Add the widgets you want to see here, then drag them into place.',
                          )
                        : __(
                              'Custom dashboards are part of the Standard plan.',
                          )}
                </p>
            </div>
            {canEdit && (
                <AddWidgetMenu
                    variant="default"
                    available={missingSystemWidgets([])}
                    onAdd={onAdd}
                />
            )}
        </div>
    );
}
